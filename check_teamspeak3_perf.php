#!/usr/bin/env php
<?php
/**
 * check_teamspeak3_perf.php
 *
 * @package Seosepa\check-teamspeak3-perf
 */

/**
 * @author Stefan Konig <github@seosepa.net>
 */

try {
    (new checkTeamspeakPerf)->run();
} catch (Exception $e) {
    // catch all for unknown errors
    echo "UNKNOWN: script execution error: " . $e->getMessage();
    exit(3);
}


class checkTeamspeakPerf
{
    CONST STATE_OK = 0;
    CONST STATE_WARNING = 1;
    CONST STATE_CRITICAL = 2;
    CONST STATE_UNKNOWN = 3;

    private $warningPacketLoss = 0;
    private $warningPing = 0;
    private $warningClientPercent = 0;
    private $criticalPacketLoss = 0;
    private $criticalPing = 0;
    private $minimalUptime = 0;
    private $criticalClientPercent = 0;
    private $ignoreVirtualstatus = false;
    private $ignoreReservedSlots = false;

    private $host = '';
    private $telnetport = '';
    private $virtualport = '';
    private $debug = false;
    private $timeout = 10;
    private $perfData = array();

    /**
     * Run the check and echo data + exitcode
     */
    public function run()
    {
        $this->getArguments();

        $teamspeak = new Teamspeak3Telnet($this->timeout, $this->debug);
        $response  = $teamspeak->connect($this->host, $this->telnetport);
        if ($response != 'OK') {
            $this->echoExit(self::STATE_CRITICAL, $response);
        }

        if ($this->virtualport == 0) {
            $serviceStatus = $this->fetchAndProcessGlobalPerf($teamspeak);
        } else {
            $serviceStatus = $this->fetchAndProcessVirtualServerPerf($teamspeak);
        }

        $teamspeak->disconnect();

        $this->echoExit(self::STATE_OK, $serviceStatus);
    }

    /**
     * @param Teamspeak3Telnet $teamspeak
     * @return string
     */
    private function fetchAndProcessGlobalPerf($teamspeak)
    {
        $response = $teamspeak->getGlobalHostInfo();

        if ($response['error'] == true) {
            $this->echoExit(
                self::STATE_CRITICAL,
                "error while fetching global host info - " . substr($response['rawresponse'], 10)
            );
        }

        $globalHostInfo = $response['response'];
        if (!isset($globalHostInfo['instance_uptime']) || !is_numeric($globalHostInfo['instance_uptime'])) {
            $this->debugLog('malformed uptime output: ' . PHP_EOL . $response['rawresponse']);
            $this->echoExit(self::STATE_CRITICAL, "malformed instance output, unable to parse uptime");
        }
        $serviceStatus = 'teamspeak3 is running for ' . $this->secondsToTimeAgo($globalHostInfo['instance_uptime']);

        // Check uptime

        if ($this->minimalUptime != 0) {
            if (!isset($globalHostInfo['instance_uptime']) || !is_numeric($globalHostInfo['instance_uptime'])) {
                $this->debugLog('malformed uptime output: ' . PHP_EOL . $response['rawresponse']);
                $this->echoExit(self::STATE_CRITICAL, "malformed uptime output, unable to parse uptime");
            }

            $this->processUptime(intval($globalHostInfo['instance_uptime']));
        }

        // Check clientPercentage

        if ($this->warningClientPercent != 0 || $this->criticalClientPercent != 0) {
            if (!isset($globalHostInfo['virtualservers_total_maxclients']) ||
                !is_numeric($globalHostInfo['virtualservers_total_maxclients']) ||
                !isset($globalHostInfo['virtualservers_total_clients_online']) ||
                !is_numeric($globalHostInfo['virtualservers_total_clients_online'])
            ) {
                $this->debugLog('malformed clientinfo output: ' . PHP_EOL . $response['rawresponse']);
                $this->echoExit(self::STATE_CRITICAL, "malformed clientinfo output, unable to parse client amount");
            }

            $maxClients     = intval($globalHostInfo['virtualservers_total_maxclients']);
            $currentClients = intval($globalHostInfo['virtualservers_total_clients_online']);

            $serviceStatus = "ts3 has {$currentClients}/{$maxClients} clients online and is running for " . $this->secondsToTimeAgo(
                    $globalHostInfo['instance_uptime']
                );

            $this->processClientPercentage($currentClients, $maxClients);
        }

        return $serviceStatus;
    }

    /**
     * @param Teamspeak3Telnet $teamspeak
     * @return string
     */
    private function fetchAndProcessVirtualServerPerf($teamspeak)
    {
        $response = $teamspeak->selectVirtualServerByPort($this->virtualport);
        if ($response['error'] == true) {
            $this->debugLog(
                "not able to select virtualserver by port {$this->virtualport}: {$response['rawresponse']}"
            );
            $this->echoExit(
                self::STATE_UNKNOWN,
                "unable to select virtualserver with port {$this->virtualport}: {{$response['rawresponse']}}"
            );
        }

        $response = $teamspeak->getServerInfo();
        if ($response['error'] == true) {
            $this->debugLog(
                "error while fetching server info for virtualserver with port {$this->virtualport}: {$response['rawresponse']}"
            );
            $this->echoExit(
                self::STATE_UNKNOWN,
                "error while fetching server info for virtualserver with port {$this->virtualport}: {{$response['rawresponse']}}"
            );
        }

        $serverInfo = $response['response'];


        // Check virtual server status
        if ($serverInfo['virtualserver_status'] != 'online') {
            if ($this->ignoreVirtualstatus) {
                $this->echoExit(
                    self::STATE_UNKNOWN,
                    "virtualserver {$this->virtualport} has status {$serverInfo['virtualserver_status']}"
                );
            } else {
                $this->echoExit(
                    self::STATE_CRITICAL,
                    "virtualserver {$this->virtualport} has status {$serverInfo['virtualserver_status']}"
                );
            }
        }


        $virtualServerInfo = $response['response'];
        if (!isset($virtualServerInfo['virtualserver_name'])) {
            $this->debugLog('malformed server output: ' . PHP_EOL . $response['rawresponse']);
            $this->echoExit(self::STATE_CRITICAL, "malformed instance output, unable to parse virtualservername");
        }
        if (!isset($virtualServerInfo['virtualserver_uptime']) || !is_numeric(
                $virtualServerInfo['virtualserver_uptime']
            )
        ) {
            $this->debugLog('malformed uptime output: ' . PHP_EOL . $response['rawresponse']);
            $this->echoExit(self::STATE_CRITICAL, "malformed instance output, unable to parse uptime");
        }
        $virtualServerName = str_replace('\s', ' ', $virtualServerInfo['virtualserver_name']);
        $serviceStatus     = "{$virtualServerName} has been running for " . $this->secondsToTimeAgo(
                $virtualServerInfo['virtualserver_uptime']
            );

        // Check packetloss

        if ($this->warningPacketLoss != 0 || $this->criticalPacketLoss != 0) {
            if (!isset($serverInfo['virtualserver_total_packetloss_total']) ||
                !is_numeric($serverInfo['virtualserver_total_packetloss_total'])
            ) {
                $this->debugLog('malformed serverinfo packetloss output: ' . PHP_EOL . $response['rawresponse']);
                $this->echoExit(self::STATE_CRITICAL, "malformed serverinfo, unable to parse packetloss");
            }

            $packetloss                   = round($serverInfo['virtualserver_total_packetloss_total'], 2);
            $this->perfData['packetloss'] = $packetloss;
            if ($this->criticalPacketLoss != 0 && $packetloss > $this->criticalPacketLoss) {
                $this->echoExit(
                    self::STATE_CRITICAL,
                    "average client packetloss {$packetloss}%"
                );
            }
            if ($this->warningPacketLoss != 0 && $packetloss > $this->warningPacketLoss) {
                $this->echoExit(
                    self::STATE_WARNING,
                    "average client packetloss {$packetloss}%"
                );
            }
        }

        // Check Ping

        if ($this->warningPing != 0 || $this->criticalPing != 0) {
            if (!isset($serverInfo['virtualserver_total_ping']) ||
                !is_numeric($serverInfo['virtualserver_total_ping'])
            ) {
                $this->debugLog('malformed serverinfo ping output: ' . PHP_EOL . $response['rawresponse']);
                $this->echoExit(self::STATE_CRITICAL, "malformed clientinfo output, unable to parse ping");
            }

            $ping                   = round($serverInfo['virtualserver_total_ping']);
            $this->perfData['ping'] = $ping;

            if ($this->criticalPing != 0 && $ping > $this->criticalPing) {
                $this->echoExit(
                    self::STATE_CRITICAL,
                    "average client ping {$ping} ms"
                );
            }
            if ($this->warningPing != 0 && $ping > $this->warningPing) {
                $this->echoExit(
                    self::STATE_WARNING,
                    "average client ping {$ping} ms"
                );
            }
        }

        // Check Uptime

        if ($this->minimalUptime != 0) {
            if (!isset($serverInfo['virtualserver_uptime']) || !is_numeric(
                    $virtualServerInfo['virtualserver_uptime']
                )
            ) {
                $this->debugLog('malformed uptime output: ' . PHP_EOL . $response['rawresponse']);
                $this->echoExit(self::STATE_CRITICAL, "malformed uptime output, unable to parse uptime");
            }

            $this->processUptime(intval($virtualServerInfo['virtualserver_uptime']));
        }

        // Check ClientPercentage

        if ($this->warningClientPercent != 0 || $this->criticalClientPercent != 0) {
            if (!isset($virtualServerInfo['virtualserver_maxclients']) ||
                !is_numeric($virtualServerInfo['virtualserver_maxclients']) ||
                !isset($virtualServerInfo['virtualserver_clientsonline']) ||
                !is_numeric($virtualServerInfo['virtualserver_clientsonline']) ||
                !isset($virtualServerInfo['virtualserver_reserved_slots']) ||
                !is_numeric($virtualServerInfo['virtualserver_reserved_slots'])
            ) {
                $this->debugLog('malformed clientinfo output: ' . PHP_EOL . $response['rawresponse']);
                $this->echoExit(self::STATE_CRITICAL, "malformed clientinfo output, unable to parse client amount");
            }

            $maxClients     = intval($virtualServerInfo['virtualserver_maxclients']);
            $currentClients = intval($virtualServerInfo['virtualserver_clientsonline']);
            $reservedSlots = intval($virtualServerInfo['virtualserver_reserved_slots']);

            $serviceStatus = "{$virtualServerName} has {$currentClients}/{$maxClients} clients online and is running for " . $this->secondsToTimeAgo(
                    $virtualServerInfo['virtualserver_uptime']
                );

            $this->processClientPercentage($currentClients, $maxClients, $reservedSlots);
        }

        return $serviceStatus;
    }

    /**
     * @param int $uptime
     */
    private function processUptime($uptime)
    {
        $this->perfData['uptime'] = $uptime;
        if ($this->minimalUptime != 0 && $uptime < $this->minimalUptime) {
            $this->echoExit(
                self::STATE_CRITICAL,
                "uptime is {$uptime} seconds (threshold min = {$this->minimalUptime})"
            );
        }
    }

    /**
     * @param int $currentClients
     * @param int $maxClients
     * @param int $reservedSlots
     */
    private function processClientPercentage($currentClients, $maxClients, $reservedSlots = 0)
    {
        $this->perfData['connectedclients'] = $currentClients;
        $this->perfData['reservedslots']    = $reservedSlots;
        $this->perfData['maxclients']       = $maxClients;
        $maxClientsMinusReserved   = $maxClients - $reservedSlots;
        if ($this->ignoreReservedSlots) {
            $maxClientsMinusReserved = $maxClients;
        }
        if ($maxClients == 0) {
            $this->echoExit(self::STATE_CRITICAL, "maximum allowed clients on server is zero");
        }
        if ($maxClientsMinusReserved == 0) {
            $this->echoExit(self::STATE_CRITICAL, "all server slots are reserved ({$reservedSlots}/{$maxClients}");
        }

        // trycatch just in case something weird is happening in this calculation
        try {
            $percentage      = round($currentClients / $maxClientsMinusReserved * 100, 1);
            $floorpercentage = floor($percentage);
        } catch (Exception $e) {
            $this->echoExit(
                self::STATE_CRITICAL,
                "error calculating clientpercentage ({$currentClients}/{$maxClientsMinusReserved})"
            );
            exit(); // never reached, there so the IDE wont mind
        }

        $this->perfData['clientpercentage'] = $percentage;

        $message = "number of clients reached {$percentage}% - {$currentClients}/{$maxClients}";
        if ($reservedSlots > 0) {
            $message .= " ({$reservedSlots} reserved)";
        }

        if ($this->criticalClientPercent != 0 && $floorpercentage > $this->criticalClientPercent) {
            $this->echoExit(self::STATE_CRITICAL, $message);
        }
        if ($this->warningClientPercent != 0 && $floorpercentage > $this->warningClientPercent) {
            $this->echoExit(self::STATE_WARNING, $message);
        }
    }

    /**
     * Get and parse arguments given
     */
    private function getArguments()
    {
        $opts = getopt(
            "",
            array(
                'help',
                'debug',
                'host:',
                'port:',
                'virtualport:',
                'timeout:',
                'warning-packetloss:',
                'critical-packetloss:',
                'warning-ping:',
                'critical-ping:',
                'minimal-uptime:',
                'warning-clients:',
                'critical-clients:',
                'ignore-reserved-slots',
                'ignore-virtualserverstatus',
            )
        );

        // show usage help when help isset or no (valid) parameters given
        if (count($opts) == 0 || isset($opts['help'])) {
            $this->echoExit(
                99,
                PHP_EOL .
                "Icinga Teamspeak3 performance/health check" . PHP_EOL . PHP_EOL .
                "* all checks are optional, they will be executed when a warning and or critical limit has been given" . PHP_EOL .
                "* when virtualport is not set, uptime & clients check will be done globally, other checks do require the virtualport to be set" . PHP_EOL . PHP_EOL .
                'Usage:' . PHP_EOL .
                '--host <localhost> --port <10011> [--virtualport <portnr>] ' . PHP_EOL .
                '[--warning-packetloss <percentage>] [--critical-packetloss <percentage>] ' . PHP_EOL .
                '[--warning-ping <ms>] [--critical-ping <ms>] ' . PHP_EOL .
                '[--warning-clients <percent>] [--critical-clients <percentage>]' . PHP_EOL .
                '[--minimal-uptime <seconds>] ' . PHP_EOL .
                '[--ignore-reserved-slots]       - a reserved slot will be counted as free slot' . PHP_EOL .
                '[--ignore-virtualserverstatus]  - go to UNKNOWN state when virtual server is offline' . PHP_EOL .
                '[--timeout <10>] [--debug]' . PHP_EOL
            );
        }

        $this->host                  = isset($opts['host']) ? $opts['host'] : 'localhost';
        $this->telnetport            = isset($opts['port']) ? intval($opts['port']) : 10011;
        $this->virtualport           = isset($opts['virtualport']) ? intval($opts['virtualport']) : 0;
        $this->debug                 = isset($opts['debug']) ? true : false;
        $this->timeout               = isset($opts['timeout']) ? intval($opts['timeout']) : 10;
        $this->warningPacketLoss     = isset($opts['warning-packetloss']) ? floatval($opts['warning-packetloss']) : 0;
        $this->criticalPacketLoss    = isset($opts['critical-packetloss']) ? floatval($opts['critical-packetloss']) : 0;
        $this->warningPing           = isset($opts['warning-ping']) ? intval($opts['warning-ping']) : 0;
        $this->criticalPing          = isset($opts['critical-ping']) ? intval($opts['critical-ping']) : 0;
        $this->minimalUptime         = isset($opts['minimal-uptime']) ? intval($opts['minimal-uptime']) : 0;
        $this->warningClientPercent  = isset($opts['warning-clients']) ? intval($opts['warning-clients']) : 0;
        $this->criticalClientPercent = isset($opts['critical-clients']) ? intval($opts['critical-clients']) : 0;
        $this->ignoreReservedSlots   = isset($opts['ignore-reserved-slots']) ? true : false;
        $this->ignoreVirtualstatus   = isset($opts['ignore-virtualserverstatus']) ? true : false;

        if ($this->virtualport == 0) {
            if ($this->warningPacketLoss != 0 || $this->criticalPacketLoss) {
                $this->echoExit(self::STATE_UNKNOWN, "cannot check packetloss without port of virtual server set");
            }
            if ($this->warningPing != 0 || $this->criticalPing) {
                $this->echoExit(self::STATE_UNKNOWN, "cannot check packetloss without port of virtual server set");
            }
        }
    }

    /**
     * @param int    $exitCode
     * @param string $serviceOutput
     */
    private function echoExit($exitCode, $serviceOutput)
    {
        $perfData = $this->getPerformanceData();

        switch ($exitCode) {
            case self::STATE_OK:
                $serviceOutput = 'OK: ' . $serviceOutput . $perfData;
                break;
            case self::STATE_WARNING:
                $serviceOutput = 'WARNING: ' . $serviceOutput . $perfData;
                break;
            case self::STATE_CRITICAL:
                $serviceOutput = 'CRITICAL: ' . $serviceOutput . $perfData;
                break;
            case self::STATE_UNKNOWN:
                $serviceOutput = 'UNKNOWN: ' . $serviceOutput;
                break;
            default:
                $exitCode = self::STATE_UNKNOWN;
        }
        echo $serviceOutput . PHP_EOL;
        exit($exitCode);
    }

    /**
     * @return string
     */
    private function getPerformanceData()
    {
        $perfData = '';

        if (count($this->perfData)) {
            $perfData = '|';
            foreach ($this->perfData as $key => $data) {
                switch ($key) {
                    case 'packetloss';
                        $data .= "%;{$this->warningPacketLoss};{$this->criticalPacketLoss}";
                        break;
                    case 'ping';
                        $data .= "ms;{$this->warningPing};{$this->criticalPing}";
                        break;
                    case 'uptime';
                        $data .= "s;;;{$this->minimalUptime}";
                        break;
                    case 'clientpercentage';
                        $data .= "%;{$this->warningClientPercent};{$this->criticalClientPercent}";
                        break;
                }
                $perfData .= "{$key}={$data} ";
            }
            trim($perfData);
        }

        return $perfData;
    }

    /**
     * @param int $inputSeconds
     * @return string humanReadableTime
     */
    private function secondsToTimeAgo($inputSeconds)
    {
        $secondsInAMinute = 60;
        $secondsInAnHour  = 60 * $secondsInAMinute;
        $secondsInADay    = 24 * $secondsInAnHour;

        // extract days
        $days = floor($inputSeconds / $secondsInADay);

        // extract hours
        $hourSeconds = $inputSeconds % $secondsInADay;
        $hours       = floor($hourSeconds / $secondsInAnHour);

        // extract minutes
        $minuteSeconds = $hourSeconds % $secondsInAnHour;
        $minutes       = floor($minuteSeconds / $secondsInAMinute);

        // extract the remaining seconds
        $remainingSeconds = $minuteSeconds % $secondsInAMinute;
        $seconds          = ceil($remainingSeconds);

        // Calculate the Return
        if ($inputSeconds < $secondsInAMinute) {
            if ($seconds == 1) {
                return $seconds . ' second';
            }
            return $seconds . ' seconds';
        }
        if ($inputSeconds < $secondsInAnHour) {
            if ($minutes == 1) {
                return $minutes . ' minute';
            }
            return $minutes . ' minutes';
        }
        if ($inputSeconds < $secondsInADay) {
            if ($hours == 1) {
                return $hours . ' hour';
            }
            return $hours . ' hours';
        } else {
            if ($days == 1) {
                return $days . ' day';
            }
            return $days . ' days';
        }
    }

    /**
     * @param string $message
     */
    private function debugLog($message)
    {
        if ($this->debug) {
            echo date('H:i:s') . " - " . $message . PHP_EOL;
        }
    }
}

/**
 * Class Teamspeak3Telnet
 *
 * handles the connection with TS3
 */
class Teamspeak3Telnet
{
    private $socket = null;
    private $timeout = 10;
    private $debug = false;


    /**
     * @param int        $timeout
     * @param bool|false $debug
     */
    public function __construct($timeout = 10, $debug = false)
    {
        $this->timeout = $timeout;
        $this->debug   = $debug;
    }

    /**
     * @param string $ipAddress
     * @param int    $port
     * @return bool
     */
    public function connect($ipAddress, $port)
    {
        // Open socket
        $this->debugLog("Connecting to tcp://{$ipAddress}:{$port}");
        $this->socket = fsockopen("tcp://{$ipAddress}", $port, $errNr, $errStr, $this->timeout);
        if ($this->socket) {
            // Set socket parameters
            socket_set_timeout($this->socket, $this->timeout);
            stream_set_blocking($this->socket, 0);
            $response = $this->getServerResponse();
            if (stristr($response, "TS3")) {
                $this->debugLog('Connected!');
                return 'OK';
            } else {
                $this->debugLog('unexpected response from server: ' . $response);
                return 'unexpected response from server';
            }
        } else {
            $this->debugLog('Error: could not connect to host: ' . $ipAddress . ' on port: ' . $port);
        }

        return "could not connect to teamspeak: {$ipAddress}:{$port}";
    }


    /**
     * @param int $virtualServerPort
     * @return string
     */
    public function selectVirtualServerByPort($virtualServerPort)
    {
        $result = $this->sendServerCommand("use port=" . $virtualServerPort);
        return $result;
    }

    /**
     * @param string $username
     * @param string $password
     * @return string
     */
    public function login($username, $password)
    {
        $result = $this->sendServerCommand(
            "login client_login_name=" . $username . " client_login_password=" . $password
        );
        return $result;
    }

    /**
     * disconnect from TS
     */
    public function disconnect()
    {
        if ($this->socket != null) {
            $this->quit();
            @fclose($this->socket);
            $this->socket = null;
            $this->debugLog("Disconnected");
        }
    }

    /**
     * send quit command to server
     */
    public function quit()
    {
        $this->sendServerCommand("quit");
    }

    /**
     * get the global host info (no virtual server)
     *
     * @return array
     */
    public function getGlobalHostInfo()
    {
        return $this->sendServerCommand("hostinfo");
    }

    /**
     * get the global host info (no virtual server)
     *
     * @return array
     */
    public function getServerInfo()
    {
        return $this->sendServerCommand("serverinfo");
    }

    /**
     * @param string $response
     * @return array
     */
    private function parseServerResponse($response)
    {
        file_put_contents('/tmp/debug.ts3.log', $response . PHP_EOL);
        if (strpos($response, 'error id=0 msg=ok') === false) {
            return array('error' => true, 'response' => $response, 'rawresponse' => $response);
        } else {
            $parsingReponse = str_replace(chr(10) . chr(13) . 'error id=0 msg=ok' . chr(10) . chr(13), '', $response);
            $responseParts  = explode(' ', $parsingReponse);
            $parsedResponse = array();
            foreach ($responseParts as $responsePart) {
                $keyvalue = explode('=', $responsePart);
                if (count($keyvalue) >= 2) {
                    $parsedResponse[$keyvalue[0]] = str_replace($keyvalue[0] . '=', '', $responsePart);
                }
            }

            return array('error' => false, 'response' => $parsedResponse, 'rawresponse' => $response);
        }
    }

    /**
     * @param string $command
     * @return string
     */
    private function sendServerCommand($command)
    {
        fwrite($this->socket, $command . Chr(13) . Chr(10));
        $this->debugLog("Sent: {$command}");
        return $this->parseServerResponse($this->getServerResponse());
    }

    /**
     * @return string
     */
    private function getServerResponse()
    {
        $response = '';
        usleep(50000); // just sleep a little for the server to respond
        while (!feof($this->socket)) {
            $buffer = fgets($this->socket, 1024);
            $response .= $buffer;
            if (substr($response, -2) == Chr(10) . Chr(13)) {
                break;
            }
        }

        $this->debugLog("Received: {$response}");
        return $response;
    }

    /**
     * @param string $message
     */
    private function debugLog($message)
    {
        if ($this->debug) {
            echo date('H:i:s') . " - " . $message . PHP_EOL;
        }
    }
}

