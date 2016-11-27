Teamspeak3 icinga2 check
===============

PHP based teamspeak server performance check, for global or virtual server:

* clients
* average packetloss
* average ping
* uptime

![Icinga2TeamspeakPerf](https://img.seosepa.net/check_teamspeak3_perf.png)

Usage
-------------

This check is tested working on:
* Ubuntu 16.04 - PHP 7.0

no need for query login, all metrics are public
```
./check_teamspeak3_perf --host <localhost> --port <10011> [--virtualport <portnr>]
[--warning-packetloss <percentage>] [--critical-packetloss <percentage>]
[--warning-ping <ms>] [--critical-ping <ms>]
[--warning-clients <percent>] [--critical-clients <percentage>]
[--minimal-uptime <seconds>]
[--ignore-reserved-slots]       - a reserved slot will be counted as free slot
[--ignore-virtualserverstatus]  - go to UNKNOWN state when virtual server is offline
[--timeout <10>] [--debug]
```

packetloss and ping check can only be used when virtual server is given.<br/>
teamspeak check performs 1/2 telnet commands per run(global/virtual), so no need for whitelisting when done remotely.

#### Global
```
hostinfo
```
#### Virtual server
```
use port=9987
serverinfo
```
