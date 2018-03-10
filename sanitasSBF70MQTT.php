#!/usr/bin/php
<?php

require(realpath(dirname(__FILE__))."/../phpMQTT/phpMQTT.php");

$devicename = "sanitasSBF70";
$serialdevice = "/dev/ttyUSB1";
$server = "192.168.2.1";     // change if necessary
$port = 1883;                     // change if necessary
$username = "";                   // set your username
$password = "";                   // set your password
$client_id = uniqid($devicename."_");; // make sure this is unique for connecting to sever - you could use uniqid()
echo ($devicename." MQTT publisher started...\n");
$mqttTopicPrefix = "home/".$devicename."/";
$iniarray = parse_ini_file($devicename."MQTT.ini",true);
if (($tmp = $iniarray[$devicename]["serialdevice"]) != "") $serialdevice = $tmp;
if (($tmp = $iniarray[$devicename]["mqttserver"]) != "") $server = $tmp;
if (($tmp = $iniarray[$devicename]["mqttport"]) != "") $tcpport = $tmp;
if (($tmp = $iniarray[$devicename]["mqttusername"]) != "") $username = $tmp;
if (($tmp = $iniarray[$devicename]["mqttpassword"]) != "") $password = $tmp;

$mqtt = new phpMQTT($server, $port, $client_id);
$mqtt->connect(true, NULL, $username, $password);


$descriptorspec = array(
   0 => array("pipe", "r"),  // stdin is a pipe that the child will read from
   1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
   2 => array("file", "/tmp/error-output.txt", "a") // stderr is a file to write to
);


$cwd = '/tmp';
$env = array();
$valuearray = array();
$weightarray = array();
$sequence = 0;
$endweight = 0;
$datareadytimeout = 0;

$process = proc_open('/usr/bin/bluetoothctl', $descriptorspec, $pipes, $cwd, $env);

if (is_resource($process)) {
    // $pipes now looks like this:
    // 0 => writeable handle connected to child stdin
    // 1 => readable handle connected to child stdout
    // Any error output will be appended to /tmp/error-output.txt
    stream_set_blocking($pipes[0], 0);
    stream_set_blocking($pipes[1], 0);

    
    while (!feof($pipes[1]))
    {
     $inputdata =  stream_get_contents($pipes[1]);
     if ($inputdata == '') 
     {
       usleep (10000);
       if ($datareadytimeout == 1)
       {
             if ($endweight > 1)
             {
               echo ("PUBLISHING ENDWEIGHT TO MQTT: ".$mqttTopicPrefix."kg/final=".$endweight."\n");
               $mqtt->publish($mqttTopicPrefix."kg/final",$endweight,0,1);
             }

       }
       if ($datareadytimeout > 0) $datareadytimeout--;
     }
     $inputdata = preg_replace('/\x1b\[[0-9];[0-9][0-9]m/', "", $inputdata); 
     $inputdata = preg_replace('/\x1b\[[0-9]m/', "", $inputdata); 
     $inputdata = preg_replace("/[^ \w\#\[\]\:\\/.\n]+/", "", $inputdata);
     if (($inputdata != '')&&(strpos($inputdata, "\n") !== false ))
     { 
     $inputdataarray = explode ("\n", $inputdata);
     foreach ($inputdataarray as $data)
     {
     $data = str_replace(array("\n", "\r"), ' ', $data);
     $data = preg_replace("/[^ \w\#\[\]\:\\/.]+/", "", $data);
     $data = preg_replace("/.*#/", "", $data); 
     $data = ltrim($data, ' '); 
     $data = rtrim($data, ' '); 
     if ((strpos($data, "/org/bluez/hci0/dev_D4_36_39_91_BA_47/service0023/char002d") !== false) && (strpos($data,"Value:") !== false))
     {
       $value = strstr($data, 'Value:');
       $value = str_replace('Value: ', '', $value); 
       echo ("VALUE RECEIVED: ".hexdec($value)."\n");
       array_push($valuearray, hexdec($value));
       while (count($valuearray) > 4)
       {
        if (($valuearray[0] == 231) && ($valuearray[1] == 88) && ($valuearray[2] == 1))
        {
         $weight= (float)(($valuearray[3]<<8) + ($valuearray[4] & 0xFF)) * 50 / 1000;
         echo ("\n\nGEWICHT=".$weight."\n\n");
          print_r($valuearray);
         $valuearray = array_slice($valuearray, 5);
             $mqtt->publish($mqttTopicPrefix."kg/now",$weight,0,0);

         array_push($weightarray, $weight);
         var_dump($weightarray);
         while (count($weightarray) > 1)
         {
          if (($weightarray[0] == $weightarray[1]))
          {
             echo ("\n##############################\nTUSSEN GEWICHT = ".$weightarray[0]."\n############################\n"); 
             $endweight = $weightarray[0];
             $datareadytimeout = 1000;
          }
          $weightarray = array_slice($weightarray, 1);
         }


        }
        else if (($valuearray[0] == 0) && ($valuearray[1] == 0) && ($valuearray[2] == 0) && ($valuearray[3] == 0) && ($valuearray[4] == 0))
        {
            echo ("\n##############################\nEIND GEWICHT = ".$weight."\n############################\n"); 
             $endweight = $weight;
             $valuearray = array_slice($valuearray, 5);
             $datareadytimeout = 1000;
        }
        else $valuearray = array_slice($valuearray, 1);
       }
     }
#     echo "'".$data."'\n";
     
      if ((strpos($data, "Failed to connect:") !== false) || (strpos($data, 'Connected: no') !== false))
      {
        fwrite($pipes[0], "connect D4:36:39:91:BA:47\n");      
        $sequence = 2;
      } 
       
          switch ($sequence)
          {
            case 0:
              fwrite($pipes[0], "connect D4:36:39:91:BA:47\n");
              $sequence=1; 
            break;
            case 1: //wait
              echo ("WATING FOR CONNECTION SUCCESSFUL\n");
              if (strpos ($data, "Connection successful") !== false) $sequence=2;
            break;
            case 2:
             echo ("SELECTING ATTRIBYTE\n");
              fwrite($pipes[0], "select-attribute /org/bluez/hci0/dev_D4_36_39_91_BA_47/service0023/char002d\n"); 
              $sequence=4; 
            break;
            case 3:
               echo ("SETTITNG NOTIFY ON\n"); 
              fwrite($pipes[0], "notify on\n"); 
              $sequence = 4;
               if (strpos ($data, '[SANITAS SBF70:/service0023/char002d]#') !== false) $sequence=4;
            break;
            case 4:
            echo ("ENABLING NOTIFY\n"); 
              fwrite($pipes[0], "notify on\n"); 
              $sequence = 5; 
            break;
            case 5:
            break;
            
          }
      }
    }
    }

    fclose($pipes[0]);
    fclose($pipes[1]);

    // It is important that you close any pipes before calling
    // proc_close in order to avoid a deadlock
    $return_value = proc_close($process);

    echo "command returned $return_value\n";
}
?>
