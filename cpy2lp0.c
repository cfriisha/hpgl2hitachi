/*
## Small program to send a .hita file directly to /dev/lp0 connected to
## a Hitachi 671-20 or similar prehistoric ploter.
## In theory this should also work with serial ports or USB ports with
## suitable RS232 converter of Centronics parallel port. You just have
## to change the device in fopen("/dev/lp0", "w").
##
## License: GNU
## Author: Carl Friis-Hansen
## Date: April 2020
## OS: Linux
## Architecture: 32bit or 64bit
##
## Compile: gcc -o cpy2lp0 cpy2lp0.c
## Path (if you like): sudo mv cpy2lp0 /usr/bin
##
## Revisions:
## 1.0 2020-04-03 Initial
##
##
##
*/

#include <stdio.h>
#include <unistd.h>
#include <sys/stat.h>

struct stat st;



int main( int argc, char *argv[] ) {
    FILE *in = NULL;
    FILE *pr = fopen("/dev/lp0", "w"); //Try /dev/usb/lp0 if you use USB interface
    char    c;
    char    s[3];
    int     inhibit = 0;
    char    rotor[4] = {'|','/','-','\\'};
    int     rotorindex = 0;
    size_t  size, bytenr;

    printf("cpy2lp0 by Carl Friis-Hansn 2020\nSends plotfile directly to /dev/lp0\n");
    printf("Usage:\n  cpy2lp0 plotfile\n------------------------------------\n");
    in = fopen(argv[1], "r");
    if(!in){printf("Failed to open input file: %s\n", argv[1]); return -1;}
    if(!pr){printf("Failed to open printer: /dev/lp0\n"); return -1;}
    stat(argv[1], &st);
    size = st.st_size;
    bytenr = 0;

    printf ("Plotting file: %s\n", argv[1]);
    while( !feof(in) ) {
        bytenr++;
        c = fgetc(in);  /* Get next charater from the input file */
        if (c == '#') { /* Skip remark lines */
            inhibit = 1;
        }
        if (inhibit == 1) {
            printf ("%c", c);
        } else {
            if (c == '\n') {    /* Just some info for the inpatient onlooker while plotting */
                printf ("\b\b\b\b\b\b%c%4ld%%", rotor[rotorindex++], (unsigned long) bytenr*100/size);
                fflush(stdout);
                if (rotorindex >= 4) {
                    rotorindex = 0;
                }
            }
            s[0] = c;
            s[1] = '\0';
            s[2] = '\0';
            fputs(s, pr);
            fflush(pr);
            usleep(100);
        }
        if (c == '\n') {
            inhibit = 0;    /* End of eventual remark line */
        }
    }
    fclose(in);
    fclose(pr);
    printf ("\nDone!\n");
    return 0;
}