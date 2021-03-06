# hpgl2hitachi
Convert .hpgl or .plt files to .hita files for use with 1980's Hitachi or Roland plotters

Project hpgl2hita created 2020 in order to get my Hitachi 671-29 6 pen
plotter going after 35 years of collecting dust.
There were two main issues:

1) Parallel and RS-232 interface
Solved in my case, with an old Toshiba Tecra 8000 laptop with both
interfaces. This laptop is running Linux Ubuntu 10.04.

2) The Roland style command set DXY
Many modern programs can create HPGL plotter files. Therefore I created
a compiler for HPGL to Hitachi's DXY format.



Although everything is done using Linux, I believe it will be easy to
configure for Windows and Mac.



The compiler hpgl2hita.php assumes you have PHP CLI installed.
Make it executable:
$ chmod a+x hpgl2hita.php
Depending on how you prefer to work, you could create a link like this:
$ cd /usr/local/bin
$ sudo ln -s /home/path/to/hpgl2hita.php hpgl2hita
See source for more information



The C-program cpy2lp0.c assumes you have gcc installed. Compile it and
use it for sending your compiled plotter files to your plotter.
For example:
$ cpy2lp0 myfile.hita
You could move the cpy2lp0 to an execute path like:
$ sudo mv cpy2lp0 /usr/local/bin
and thereby have easier access to it from anywhere.
See the source for more information.



All the best good luck
Carl Friis-Hansen
