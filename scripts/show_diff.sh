#/bin/bash

grep "^\(+\|-\)" ../steps/03diff/*.diff				 > show_diff.log

echo -e "\n\nSummary\n"						>> show_diff.log

grep "^\(+\|-\)" ../steps/03diff/*.diff | grep Anzahl		>> show_diff.log
