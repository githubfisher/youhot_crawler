#!/bin/bash
PWD='/root/crawlers/crawler'
file='/x.php'
num=0
n=''
while ((num < 200))
do
    let "num++"
    pids=$(ps -ef |grep "${PWD}${num}${file}" |grep -v "$0" |grep -v "grep" | awk '{print $2}')
    if [ "${pids}"x = "${n}"x ] 
    then
	echo 'empty'
	continue
    else
        for id in ${pids}
        do
	    echo "Find ${PWD}${num}${file} process: ${id}"
	    kill -9 ${id}
	    echo "Kill process done!"
        done
    fi
done
