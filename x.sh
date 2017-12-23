#!/bin/bash
PWD="$( cd "$( dirname "$0" )" && pwd )"
echo "*/2 * * * * $PWD/x.sh >> /dev/null"

pid=`ps -ef |grep "/usr/bin/php $PWD/x.php" |grep -v "grep" |wc -l`

check(){
	before=$(date -d -10minute +%s)
	current=$(stat --format=%Y $1)
	if [ $current -le $before ]
	then
	    echo 'x.php is out running, restart it now'
	    for id in $2
	    do
		kill -9 ${id}
	    done
	    /usr/bin/php $3/x.php start > nohup.out 2>&1 &
        else
	    echo 'x.php is running good'
	fi
}

dir=./logs
postion=$(cd ${dir} && pwd)
name="${PWD}/x.php"
pids=$(ps -ef |grep "${name}" |grep -v "$0" |grep -v "grep" | awk '{print $2}')
if [ $pid -gt 0 ];then
    echo "x.php exists..."
    log_path=${postion}"/run_log"
    if [ -e ${log_path} ]
    then
        check ${log_path} ${pids} ${PWD}
    else
	echo 'x.php is out running, restart it now'
	for id in ${pids}
	    do
 		kill -9 ${id}
	   done
	   /usr/bin/php $PWD/x.php start > nohup.out 2>&1 &
    fi
else
    echo "x.php start..."
    /usr/bin/php $PWD/x.php start > nohup.out 2>&1 &
fi
