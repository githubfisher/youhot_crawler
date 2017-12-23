#!/bin/bash
dirname='/root/crawlers/crawler'
filename='/x.php'
num=2
while(( num <= 200))
do
cp -r "${dirname}1${filename}" ${dirname}${num}${filename};
let "num++"
done
echo 'Copy Done!'

