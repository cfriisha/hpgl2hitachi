#! /usr/bin/php
#
# Convert .plt or .hpgl files to Roland style files for Hitachi 671-20
#
<?php
# Author: Carl Friis-Hansen
# Initial creation: March 2020
# License: GNU
# Hardware during development:
#   Hitachi 671-20 Plotter from 1986 (model 1985)
#   Plotter connected to parallel port of
#   Toshiba Tecra 8000 from 1999 with Ubuntu 10.04 256KB RAM.
#   Standard parallel cable, full wired (Centronix and D-25)
#   Software development on Lenovo B50-10 Ubuntu 18.04 4GB RAM
#     PHP 7.2.24-0ubuntu0.18.04.3 (cli)
#     GCC Ubuntu 7.5.0-3ubuntu1~18.04
#     KiCad 5.1.5-52549c5~84~ubuntu18.04.1, release build
#     Geany 1.32
# Video of the working Hitachi 671-20 plotter is available on Youtube:
#   https://toutube.com/.............
# Whishes from Carl Friis-Hansen:
#   Please comment at GitHub if you have a similar prehistoric Hitachi
#   or Roland plotter still usable :-)
# Q: Why is this a PHP program?
# A: Because I have 20 years experience with PHP and it is very direct
#    and easy to use for developments like this.
# Q: Why is hpgl2lp0 a C-program source
# A: Because C-compiler (gcc) is default installed on Linux, thus if
#    you have an old Linux computer, like me, to drive your old plotter,
#    it is the most handy programming language.
# Q: What programs has it been tested with?
# A: KiCad .plt files and InkScape .hpgl files.
#
# Revisions:
# 1.0 2020-04-03 Initial
#
#
#

/*
 * Reference to HPGL partly used:
 * https://www.isoplotec.co.jp/HPGL/eHPGL.htm
 *
 * Reference to DXY partly used:
 * Roland DG
 * X-Y Plotter
 * DXY-800
 * DXY-101
 * Operation Manual
 * Page 15 to 21
 *
 * Not all commands work with Hitachi 671-20, exepted are:
 * G command
 *
 * This PHP program is used to convert for example HPGL output from
 * KiCad to a file that can be sent to the Hitachi plotter.
 *
 * Sending the file to the plotter's parallel port, is done via a separate
 * C-program with the name cpy2lp0 with source cpy2lp0.c
 * Compile with:
 *   gcc -o cpy2lp0 cpy2lp0.c
 * This program writes directly to the port on a Linux machine, without
 * involving CRUPS or any buffers. During experiments, I found that
 * handshake or similar was failing if the port is buffered or filtered.
 * The 100uSec sleep is to catch signals from Hitachi being too slow for
 * modern computers.
 */
// ---------------------------------------------------------------------
// ---- Adjust following to your needs                              ----
//
define ('DEBUG', false);    // Additional info on terminal (flase)
define ('XDIV', 4);         // Division Hitachi 0.1mm / HPGL 0.025mm (4)
define ('YDIV', 4);         // Division Hitachi 0.1mm / HPGL 0.025mm (4)
define ('XOFF', 0);         // Offset if needed (0)
define ('YOFF', 0);         // Offset if needed (0)
define ('PENW', 2);         // Pen width (default 2 * 0.1mm (Integer 2)
define ('CRLF', "\n");      // Linux line endings (\n)
define ('XHEADER', true);   // Extra header info in outout file (true)
//
// ----                                                             ----
// ---------------------------------------------------------------------

if ($argc !== 2 && $argc !== 3) {
    echo 'Usage: hpgl2hita filename [scale]' . PHP_EOL;
    echo 'This will use filename.plt or filename.hpgl as source and generate filename.hita' . PHP_EOL;
    echo 'Default scale is 1.0' . PHP_EOL;
    exit(1);
}

$finName = $argv[1] . '.plt';
if (! file_exists($finName)) {
    $finName = $argv[1] . '.hpgl';
    if (! file_exists($finName)) {
        echo 'Neither ' . $argv[1] . '.plt nor ' . $argv[1] . '.hpgl can be accessed.' . PHP_EOL;
        exit(2);
    }
}

$foutName = $argv[1] . '.hita';
$finSize = filesize($finName);
$fout = '';
$ftemp = '# Hitachi 671-20 plot file converted from a .plt or .hpgl file.' . CRLF;
$ftemp .= '# In-file: ' . $finName . ' - Out-file: ' . $foutName . CRLF;
$ftemp .= '# Date converted: ' . date('Y-m-d at H:i') . CRLF;
$ftemp .= '# Converted by: ' . get_current_user() . ' - with: ' . $argv[0] . CRLF;
$ftemp .= '# ------------------------------------------------------------' . CRLF;
$ftemp .= '# ----------- Try plot using "cpy2lp0" C-program -------------' . CRLF;
$ftemp .= '# ------------------------------------------------------------' . CRLF;

// Flags
$pd = false;
$pm = false;
// if pm && !pd then circle define
// if pm && pd then square define
$pmpar = 0;     // Used with square for xy corner 1..4
$pmtype = ' ';  // ' '=none / 'C'=Circle / 'Q'=sQuare
// Normal x,y
$x = 0;
$y = 0;

// Array to hold xy sequences
$sxy = [];
$sxyi = 0;
$scmd = '';

// Circle center
$cx = 0;
$cy = 0;

// Circle radius
$cr = 0;

// Square corners
$sqx = [1,2,3,4];
$sqy = [1,2,3,4];

// Scale
if ($argc == 3) {
    $ps = $argv[2];
} else {
    $ps = 1.0;
}

// Set pen width
$penw = PENW;

echo 'Converting ' . $finName . ' to ' . $foutName . ' ... ' . PHP_EOL;

/*  HPGL code
PU;PA 8948,3855;
PD;PA 8951,3858;
PA 8956,3861;
PA 8971,3861;

PU;PA 6535,4438;
PM 0; PA 6597,4438;CI 62;PM 2; FP; EP;
PU;PA 9321,4438;
PM 0; PA 9442,4438;CI 120;PM 2; FP; EP;

PM 0;   (define)
PD;PA 6514,3777;    (Point 0) (draw square)
PA 6578,3777;       (Point 1)
PA 6578,3523;       (Point 2)
PA 6514,3523;       (Point 3) (Same as starting point)
PM 2; FP; EP; (end PM, fill square and outline square)
*/
/*  DXY code (Hitachi 671-20)
M987,714
D988,715
D989,715
D993,715

C9442,4438,120,0,360
*/



//
// ---- Main control loop ----------------------------------------------
//
$li = 0;
$lo = 0;
$lines = file($finName, FILE_IGNORE_NEW_LINES);
foreach ($lines as $line) {
    $li++;
    $linesa = explode (";", $line);
    foreach ($linesa as $cmd) {
        $cmd = trim($cmd);
        if ((substr($cmd,0,2) == 'PU') && (strlen($cmd) > 4)) {
            $pd = false;
            $cmd = 'PA' . substr ($cmd, 2);
        } else if ((substr($cmd,0,2) == 'PD') && (strlen($cmd) > 4)) {
            $pd = true;
            $cmd = 'PA' . substr ($cmd, 2);
        }
        switch (substr ($cmd, 0, 2)) {
            case 'IN':
                $ftemp .= 'H' . CRLF;   // Reset the plotter
                break;
            case 'SP':
                cmd_sp ($cmd);  // Select pen 1..6 or 0 to return current pen
                break;
            case 'PU':
                $pd = false;// Used as flag to determine D or M for PA cmds
                break;
            case 'PD':
                $pd = true; // Used as flag to determine D or M for PA cmds
                break;
            case 'PM':
                cmd_pm ($cmd);  // Program object square, circle, etc.
                break;
            case 'PA':
                cmd_pa ($cmd);  // Pen Absolute advance in either Draw or Move mode
                break;
            case 'PR':
                cmd_pr ($cmd);  // Pen Relative advance in either Draw or Move mode
                break;
            case 'CI':
                cmd_ci ($cmd);  // Circle
                break;
            case 'EP':
                cmd_ep ($cmd);  // Draw last object Extend
                break;
            case 'FP':
                cmd_fp ($cmd);  // Fill last object
                break;
            default:
                $nl = false;    // Skip \n as no nown command detected
        }   // End Switch
    }   // End foreach cmd
}   // End foreach line (Main control loop)



//
// Change from Linux line endings to Microsoft CR-LF
// Strange, but it seems Hitachi had Microsoft DOS in mind, when they
// designed the Hitachi 671-20.
// I have tried with normal Linux/Unix endings, which was not successful.
//
$fout = '';
$ftempLength = strlen ($ftemp);
for ($i=0; $i < $ftempLength; $i++) {
    $s = substr($ftemp, $i, 1);
    if ($s !== "\n") {
        $fout .= $s . "";
    } else {
        if ($s !== "\r") {
            $fout .= "\r\n";
        }
    }
}



//
// Write $ftemp to file on disk
//
file_put_contents($foutName, $fout);

echo '... done!' . PHP_EOL;



//
// ==== Function section ===============================================
//



//
// Process 'SPn' (n: 0..6 for Hitachi 671-20)
// Return Jn
//
function cmd_sp ($cmd) {
    global $ftemp, $li, $lo;
    if (strlen ($cmd) === 3) {
        $lo++;
        if (DEBUG) echo "LI/LO=" . $li . "/" . $lo . PHP_EOL;
        $ftemp .= 'J' . substr ($cmd, 2, 1) . CRLF;
    }
}



//
// Process 'PA 8948, 3855'
// May return 'M #, #' or 'D #,#' depending on circumstances
//
function cmd_pa ($cmd) {
    global $x, $y, $pd, $ftemp, $pm, $cx, $cy, $sqx, $sqy, $pmtype, $pmpar, $li, $lo, $sxy, $sxyi;
    if (strlen ($cmd) > 2) {
        split_cmd_to_x_y ($cmd);
        if ($pm) {
            if ($pd) {      // Square or triangle corners
                $sqx[$pmpar] = $x;
                $sqy[$pmpar] = $y;
                $pmpar++;
            } else {        // Circle center
                $pmtype = 'C';
                $cx = $x;
                $cy = $y;
            }
        } else {
            $lo++;
            if (DEBUG) echo "LI/LO=" . $li . "/" . $lo . PHP_EOL;
            if ($pd) {      // Standard draw
                d(0, $x, $y);
            } else {        // Standard move
                m(0, $x, $y);
                $cx = $x;
                $cy = $y;
            }
            while ( ($sxyi + 2) < count($sxy) ) {
                next_x_y ();
            }
        }
    }
}



//
// Process 'PR 948, 855'
// May return 'R #, #' or 'I #,#' depending on circumstances
// Function not tested, as I have seen it used.
//
function cmd_pr ($cmd) {
    global $x, $y, $pd, $ftemp, $li, $lo;
    if (strlen ($cmd) > 2) {
        split_cmd_to_x_y ($cmd);
        $lo++;
        if (DEBUG) echo "LI/LO=" . $li . "/" . $lo . PHP_EOL;
        if ($pd) {
            $ftemp .= 'I' . $x . ',' . $y . CRLF;
        } else {
            $ftemp .= 'R' . $x . ',' . $y . CRLF;
        }
    }
}



//
// Process 'PM 0' or 'PM 2'
//          0123
// Determine what object is to be programmed
//
function cmd_pm ($cmd) {
    global $pm, $pmpar, $li, $pmtype;
    if (DEBUG) echo $cmd . PHP_EOL;
    if (substr($cmd, 3, 1) == '0') {
        $pm = true;
        $pmpar = 0;
    } else if (substr($cmd, 3, 1) == '2') {
        $pm = false;
        if ($pmpar == 4) {
            $pmtype = 'Q';
        } else if ($pmpar == 3) {
            $pmtype = 'T';
        } else if ($pmpar == 2) {
            $pmtype = 'P';
        } else if ($pmpar == 0) {
            $pmtype = 'C';
        } else {
            echo "Fatal error 296: pmpar=" . $pmpar . " after PM 2 in LI " . $li . PHP_EOL;
            exit (1);
        }
        if (DEBUG) echo "pmpar= " . $pmpar . PHP_EOL;
    } else {
        // echo "Strange: " . $cmd . PHP_EOL;
    }
}



//
// Process 'CI 120' (circle)
//
function cmd_ci ($cmd) {
    global $pm, $cx, $cy, $cr, $pmtype, $ftemp, $li, $lo, $ps;
    $cr = round(trim(substr($cmd, 3)) / XDIV * $ps);
    $lo++;
    if (DEBUG) echo "LI/LO=" . $li . "/" . $lo . PHP_EOL;
    if ($pm) {
        $pmtype = 'C';
        if (DEBUG) echo "cr = " . $cr . " pmtype = " . $pmtype . PHP_EOL;
    } else {
        if (DEBUG) echo 'Circle standard' . PHP_EOL;
        c($cx, $cy, $cr);
    }
}



//
// Process 'EP' ('CI $cr') (circle) (or square or poly-line)
// Poly-line currently not implemented or defined
//
function cmd_ep ($cmd) {
    global $ftemp, $cx, $cy, $cr, $pmtype, $sqx, $sqy, $pmpar, $li, $lo;
    $lo++;
    if (DEBUG) echo "LI/LO=" . $li . "/" . $lo . PHP_EOL;
    if ($pmtype == 'C') {
        if (DEBUG) echo 'Circle extend' . PHP_EOL;
        $ftemp .= 'C' . $cx . ',' . $cy . ',' . $cr . ',0,360' . CRLF;
    } else {
        if ($pmtype == 'Q') {
            if (DEBUG) echo 'Square extend' . PHP_EOL;
        } else if ($pmtype == 'T') {
            if (DEBUG) echo 'Triangle extend' . PHP_EOL;
        } else if ($pmtype == 'P') {
            if (DEBUG) echo 'Poly-line extend' . PHP_EOL;
            /* ---- Poly line / Convert to tyoe 'P'
            PU;PA 9035,2770;    $cx, $cy
            PM 0;
            PD;PA 9035,2835;    $sqx[0], $sqy[0]
            PA 9035,2770;       $sqx[1], $sqy[1]
            PM 2; FP; EP;
             */
            if ($sqx[0] == $sqx[1]) {    // Horizontal
                // Seems like crap
                return;
            }
        }
        // $ftemp .= 'M' . $sqx[$pmpar - 1] . ',' . $sqy[$pmpar - 1] . CRLF;
        m(0, $sqx[$pmpar - 1], $sqy[$pmpar - 1]);
        printf( "Extend\n3: %5.1f , ", $sqx[3]);
        printf( "%5.1f \n", $sqy[3]);
        for ($i = 0; $i < $pmpar; $i++) {
            // $ftemp .= 'D' . $sqx[$i] . ',' . $sqy[$i] . CRLF;
            d($i);
            printf( "%d: %5.1f , ", $i, $sqx[$i]);
            printf( "%5.1f \n", $sqy[$i]);
        }
        echo "\n";
    }
}



//
// Process 'FP' ('CI $cr-2','CI $cr-4','CI $cr-6',...'CI 2', ) (circle or square)
// Fill polygon is trying to draw lines or circles PENW apart to cover the boundaries
//
function cmd_fp ($cmd) {
    global $ftemp, $cx, $cy, $cr, $pmtype, $sqx, $sqy, $pmpar, $li, $lo, $penw;
    if ($pmtype == 'C') {   // Circle
        if (DEBUG) echo 'Circle fill' . PHP_EOL;
        $lcr = $cr;
        while ($lcr > $penw) {
            $lcr -= $penw;
            //$ftemp .= 'C' . $cx . ',' . $cy . ',' . $lcr . ',0,360' . CRLF;
            c($cx, $cy, $lcr);
        }
    } else {                // Square
/*
        x3y3 ---------- x0y0
        |                  |
        |                  |
        x2y2 ---------- x1y1

        or

                     x2y2
                    /    \
                   /      \
                  /        \
                 /          \
                /            \
               /              \
              /                \
             /                  \
            /                    \
           /                      \
          /                        \
         /                          \
        x1y1 ------------------- x0y0
*/
        if ($pmtype == 'Q') {  // Square
            $lo++;
            if (DEBUG) echo "LI/LO=" . $li . "/" . $lo . PHP_EOL;
            if (DEBUG) echo 'Square fill' . PHP_EOL;
            sort_square_matrix();
            if (DEBUG) {
                printf( "%5.1f , ", $sqx[3]);
                printf( "%5.1f ----------- ", $sqy[3]);
                printf( "%5.1f , ", $sqx[0]);
                printf( "%5.1f \n\n", $sqy[0]);
                printf( "%5.1f , ", $sqx[2]);
                printf( "%5.1f ----------- ", $sqy[2]);
                printf( "%5.1f , ", $sqx[1]);
                printf( "%5.1f \n", $sqy[1]);

                echo '------------------------------' . PHP_EOL;
            }
            // Preserve original $sqx and $sqy in $sqxb and $sqyb
            $sqxb = json_decode( json_encode($sqx), true);
            $sqyb = json_decode( json_encode($sqy), true);

            m(3);   // Start at correct pos
            while ( (($sqx[0]-$sqx[3]) > ($penw*2)) &&
                    (($sqx[1]-$sqx[2]) > ($penw*2)) &&
                    (($sqy[3]-$sqy[2]) > ($penw*2)) &&
                    (($sqy[0]-$sqy[1]) > ($penw*2)) ) {
                penw_step_square();
                if (DEBUG) {
                    printf( "%5.1f , ", $sqx[3]);
                    printf( "%5.1f / ", $sqy[3]);
                    printf( "%5.1f , ", $sqx[0]);
                    printf( "%5.1f / ", $sqy[0]);
                    printf( "%5.1f , ", $sqx[1]);
                    printf( "%5.1f / ", $sqy[1]);
                    printf( "%5.1f , ", $sqx[2]);
                    printf( "%5.1f\n",  $sqy[2]);
                }
                d(3); // Just keepmed down
                for ($i=0; $i<4; $i++) {
                    d($i);  // Draw all 4
                }
            }

            // Restore original $sqx and $sqy from $sqxb and $sqyb
            $sqx = json_decode( json_encode($sqxb), true);
            $sqy = json_decode( json_encode($sqyb), true);
        } else if ($pmtype == 'T') { // Triangle
            $lo++;
            if (DEBUG) echo "LI/LO=" . $li . "/" . $lo . PHP_EOL;
            if (DEBUG) echo 'Triangle fill' . PHP_EOL;
            // FP for trianle not yet implemented
        } else if ($pmtype == 'P') { // Poly-line
            $lo++;
            if (DEBUG) echo "LI/LO=" . $li . "/" . $lo . PHP_EOL;
            if (DEBUG) echo 'Poly-line fill' . PHP_EOL;
            // FP for poly-line not yet implemented
        } else {
            // Neither square or triangle
            echo "Fatal error 437: pmpar=" . $pmpar . " after PM 2 in LI " . $li . PHP_EOL;
            exit (1);
        }
    }
}


//
// Tangens to a given 0..45 degree vector in a square
//
function tanx_penw($x1, $y1, $x2, $y2) {
    $vx = abs($x2 - $x1);
    $vy = abs($y2 - $y1);
    $c = sqrt( ($vx*$vx) + ($vy*$vy)  );
    $angle = acos(abs($vx/$c));
    return tan($angle);
}



//
// Tangens to a given 90..135 degree vector in a square
//
function tany_penw($x1, $y1, $x2, $y2) {
    $vx = abs($x2 - $x1);
    $vy = abs($y2 - $y1);
    $c = sqrt( ($vx*$vx) + ($vy*$vy)  );
    $angle = acos(abs($vy/$c));
    return tan($angle);
}



//
// Adjust points 3, 0, 1, 2 PENW inwards in square
//
// This function needs a bit more work to be reliable for other than
// 0 and 45 degree angles of squares/rectangles.
// It has been a pain the but so far, but seems to work fine for KiCad
// drawings. In particular PCB, as diagrams would normally be printed on
// a laser or ink printer.
//
function penw_step_square() {
    global  $sqx, $sqy, $penw;

    $sqx[3] += $penw - $penw * tanx_penw($sqx[3], $sqy[3], $sqx[0], $sqy[0]) * 0;
    $sqy[3] -= $penw - $penw * tanx_penw($sqx[3], $sqy[3], $sqx[0], $sqy[0]);

    //$sqx[3] += $penw;
    //$sqy[3] -= $penw;

    $sqx[0] -= $penw - $penw * tany_penw($sqx[0], $sqy[0], $sqx[1], $sqy[1]);
    $sqy[0] -= $penw - $penw * tany_penw($sqx[0], $sqy[0], $sqx[1], $sqy[1]) * 0;

    $sqx[1] -= $penw - $penw * tanx_penw($sqx[1], $sqy[1], $sqx[2], $sqy[2]) * 0;
    $sqy[1] += $penw - $penw * tanx_penw($sqx[1], $sqy[1], $sqx[2], $sqy[2]);

    $sqx[2] += $penw - $penw * tany_penw($sqx[2], $sqy[2], $sqx[3], $sqy[3]);
    $sqy[2] += $penw - $penw * tany_penw($sqx[2], $sqy[2], $sqx[3], $sqy[3]) * 0;
}


//
// Draw command
//
function d($p, $x=0, $y=0) {
    global  $ftemp, $sqx, $sqy;
    if ($x == 0) $x = $sqx[$p];
    if ($y == 0) $y = $sqy[$p];
    $ftemp .= 'D' . round($x) . ',' . round($y) . CRLF;
}



//
// Move command
//
function m($p, $x=0, $y=0) {
    global  $ftemp, $sqx, $sqy;
    if ($x == 0) $x = $sqx[$p];
    if ($y == 0) $y = $sqy[$p];
    $ftemp .= 'M' . round($x) . ',' . round($y) . CRLF;
}



//
// Cirle command
//
function c($cx, $cy, $lcr, $angle_begin=0, $angle_end=360) {
    global  $ftemp;
    $ftemp .=   'C' . round($cx) . ',' . round($cy) .
                ',' . round($lcr) . ',' .
                round($angle_begin) . ',' . round($angle_end) . CRLF;
}



//
// Make sure points appear in predefined sequence around the square
//
function sort_square_matrix() {
/*
        x3y3 ---------- x0y0
        |                  |
        |                  |
        x2y2 ---------- x1y1

    Valid for up to 90 degree counter clockwise rotation.
*/
    global $sqx, $sqy;
    $qx = json_decode( json_encode($sqx), true);
    $qy = json_decode( json_encode($sqy), true);
    // 3 = Find smallest X. If two are smallest then use the one with largest Y
    // 2 = Fimd smallest Y. If two are smallest then use the one with smallest X
    // 1 = Find largest X. If two are largest then the one with smallest Y
    // 0 = Finf largest Y. If two are largest then the one with largst Y

    // 3 = Find smallest X. If two are smallest then use the one with largest Y
    $mina = array_keys($qx, min($qx));
    if (count($mina) > 1) {
        if ($qy[$mina[0]] < $qy[$mina[1]]) {
            $sqx[3] = $qx[$mina[1]];
            $sqy[3] = $qy[$mina[1]];
        } else {
            $sqx[3] = $qx[$mina[0]];
            $sqy[3] = $qy[$mina[0]];
        }
    } else {
        $sqx[3] = $qx[$mina[0]];
        $sqy[3] = $qy[$mina[0]];
    }

    // 2 = Fimd smallest Y. If two are smallest then use the one with smallest X
    $mina = array_keys($qy, min($qy));
    if (count($mina) > 1) {
        if ($qx[$mina[0]] < $qx[$mina[1]]) {
            $sqx[2] = $qx[$mina[0]];
            $sqy[2] = $qy[$mina[0]];
        } else {
            $sqx[2] = $qx[$mina[1]];
            $sqy[2] = $qy[$mina[1]];
        }
    } else {
        $sqx[2] = $qx[$mina[0]];
        $sqy[2] = $qy[$mina[0]];
    }

    // 1 = Find largest X. If two are largest then the one with smallest Y
    $mina = array_keys($qx, max($qx));
    if (count($mina) > 1) {
        if ($qy[$mina[0]] < $qy[$mina[1]]) {
            $sqx[1] = $qx[$mina[0]];
            $sqy[1] = $qy[$mina[0]];
        } else {
            $sqx[1] = $qx[$mina[1]];
            $sqy[1] = $qy[$mina[1]];
        }
    } else {
        $sqx[1] = $qx[$mina[0]];
        $sqy[1] = $qy[$mina[0]];
    }

    // 0 = Finf largest Y. If two are largest then the one with largst X
    $mina = array_keys($qy, max($qy));
    if (count($mina) > 1) {
        if ($qx[$mina[0]] < $qx[$mina[1]]) {
            $sqx[0] = $qx[$mina[1]];
            $sqy[0] = $qy[$mina[1]];
        } else {
            $sqx[0] = $qx[$mina[0]];
            $sqy[0] = $qy[$mina[0]];
        }
    } else {
        $sqx[0] = $qx[$mina[0]];
        $sqy[0] = $qy[$mina[0]];
    }
    //echo '---------------------------' . PHP_EOL;
    //print_r($sqx);
    //print_r($sqy);
}



//
// 'PA 8948, 3855' to $x=8948 and $y=3855
// Grab all x,y pairs in the command
//
// In some plot files you may find a draw command followed by a
// sequense of x1,y1,x2,y2...xn,yn followed by the terminating ';'
// This function, together with function next_x_y will handle this
// issue and separate into separate 'D #,#' or unthinkable 'M #,#'
// commands.
//
function split_cmd_to_x_y ($cmd) {
    global $x, $y, $ps, $sxy, $sxyi, $scmd;
    $sxy = [];
    $sxyi = 0;
    $scmd = substr ($cmd, 0, 2);
    $cmd = substr ($cmd, 2);
    $sxy = explode (",", $cmd);
    $x = trim ($sxy[$sxyi]);
    $y = trim ($sxy[$sxyi + 1]);
    $x = $x + XOFF;
    $y = $y + YOFF;
    $x = round ($x / XDIV * $ps);
    $y = round ($y / YDIV * $ps);
}



//
// Inter related to function split_cmd_to_x_y above
//
function next_x_y () {
    global $x, $y, $ps, $sxy, $sxyi, $lo, $li, $pd, $cx, $cy, $ftemp;
    if (($sxyi + 2) < count($sxy)) {
        $sxyi += 2;         // Next pair
        $x = trim ($sxy[$sxyi]);
        $y = trim ($sxy[$sxyi + 1]);
        $x = $x + XOFF;
        $y = $y + YOFF;
        $x = round ($x / XDIV * $ps);
        $y = round ($y / YDIV * $ps);
        $lo++;
        if (DEBUG) echo "LI/LO=" . $li . "/" . $lo . PHP_EOL;
        if ($pd) {      // Standard draw
            $ftemp .= 'D' . $x . ',' . $y . CRLF;
        } else {        // Standard move
            $ftemp .= 'M' . $x . ',' . $y . CRLF;
            $cx = $x;
            $cy = $y;
        }
        return true;
    } else {
        return false;
    }
}



//
// ==== End of program =================================================
//
