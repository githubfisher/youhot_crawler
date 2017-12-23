#!/bin/bash
dirname='/root/crawlers/crawler'
filename='/x.sh'
num=1
while ((num <= 200))
do
cd "${dirname}${num}/"
sleep 1
echo ${dirname}${num}${filename}
source ${dirname}${num}${filename} >> /dev/null
let "num++"
done
echo 'Running Now!'
