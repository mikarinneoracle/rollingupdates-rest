<?php

// Mika Rinne ORACLE
$contentType = $_SERVER["CONTENT_TYPE"];
$data = [];
if($contentType && $contentType == 'application/json')
{
    $data = json_decode(file_get_contents("php://input"), true);
} else
{
    $data = $_POST;
}
$token = $data['token'];
$host = $data['host'];
$deployment = $data['deployment'];
$backendName = $data['backendname'] ? $data['backendname'] : '';
$filter = $data['filter'] ? $data['filter'] : '';

// For testing cmdline
if(!$token || !$host || !$deployment)
{
  $token = $argv[1];
  $host = $argv[2];
  $deployment = $argv[3];
  $backendName = $argv[4];
  $filter = $argv[5];
}

if(!$token || !$host || !$deployment)
{
  http_response_code(400);
  if($contentType && $contentType == 'application/json')
  {
      $ret = [];
      $ret['error'] = "Please fill in REST parameters: authorization, host, deployment, [backendname], [filter]";
      echo json_encode($ret, true);
  } else {
      echo "Please use POST parameters: authorization host deployment [backendname] [filter]\n";
  }
  exit;
}

$auth = 'Bearer ' . $token;

try {
  $ret = getDeployments($auth, $host);
  $obj = json_decode($ret, true);
  $deployments = $obj['deployments'];
  $foundDeployment = '';
  foreach($deployments as $dm)
  {
    if(strpos($dm['deployment_id'], $deployment) > -1)
    {
      $foundDeployment = $dm['deployment_id'];
    }
  }
  if(!$foundDeployment)
  {
    http_response_code(404);
    $error = $deployment . " not found.";
    if($contentType && $contentType == 'application/json')
    {
        $ret = [];
        $ret['error'] = $error;
        echo json_encode($ret, true);
    } else {
        echo $error . '\n';
    }
  } else {
    $ret = getContainers($auth, $host, $foundDeployment);
    $obj = json_decode($ret, true);
    $containers = $obj['containers'];
    if(count($containers) == 0)
    {
      http_response_code(404);
      $error = "No containers found.";
      if($contentType && $contentType == 'application/json')
      {
          $ret = [];
          $ret['error'] = $error;
          echo json_encode($ret, true);
      } else {
          echo $error . '\n';
      }
    } else {
      $ret = recycle($auth, $host, $foundDeployment, $containers, $filter, $backendName);
      http_response_code(200);
      if($contentType && $contentType == 'application/json')
      {
          $arr = explode("\n", $ret);
          $resp = [];
          $resp['response'] = $arr;
          echo json_encode($resp, true);
      } else {
          echo $ret . '\n';
      }
    }
  }
} catch (Exception $e) {
   http_response_code(408);
   $error = $e->getMessage();
   if($contentType && $contentType == 'application/json')
   {
       $ret = [];
       $ret['error'] = $error;
       echo json_encode($ret, true);
   } else {
       echo $error . '\n';
   }
   exit;
}

function recycle($auth, $host, $deployment, $containers, $filter, $backendName)
{
  $filteredContainers = array();
  if($filter)
  {
    foreach($containers as $container)
    {
      if(strpos($container['container_name'], $filter) > -1)
      {
        $filteredContainers[] = $container;
      }
    }
  } else {
    $filteredContainers = $containers;
  }

  $ret = '';
  foreach($filteredContainers as $container)
  {
    $recycled = false;
    if($backendName)
    {
        $ret .= "Disable haproxy for " . $container['container_id'] . "\n";
        haproxyCmd($backendName, $container['container_id'], 'disable');
        sleep(5);
    }
    $ret .= "Kill container " . $container['container_id'] . "\n";
    killContainer($auth, $host, $container['container_id']);
    $i = 0;
    while(!$recycled && $i < 20) // EXIT AFTER 1 minute FOR SAFETY
    {
      $i++;
      sleep(3);
      $cons = getContainers($auth, $host, $deployment);
      $obj = json_decode($cons, true);
      $testCont = $obj['containers'];
      $found = false;
      foreach($testCont as $tc)
      {
        if($tc['container_id'] == $container['container_id'])
        {
          $found = true;
        }
      }
      $allRunning = true;
      foreach($testCont as $tc)
      {
        if($tc['state'] != 'Running')
        {
          $allRunning = false;
        }
      }
      if(!$found && $allRunning)
      {
        $recycled = true;
      }
    }
    if($i > 20)
    {
      //throw new Exception("Timeout");
      return "Timeout";
    }
  }
  if($filter)
  {
    return $ret . "All " . $filter . " recycled.";
  } else {
      return $ret . "All recycled.";
  }
}

function getDeployments($auth, $host)
{
  $curl = curl_init();
  $headers = ['Authorization: ' . $auth ];

  curl_setopt_array($curl, array(
      CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_URL => 'https://' . $host . '/api/v2/deployments/',
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_SSL_VERIFYHOST => 0,
      CURLOPT_SSL_VERIFYPEER => 0,
      CURLOPT_FAILONERROR => 1
  ));

  // Send the request & save response to $resp
  $resp = curl_exec($curl);

  if($errno = curl_errno($curl)) {
      $error_message = curl_strerror($errno);
      curl_close($curl);
      throw new Exception($error_message);
  } else {
      curl_close($curl);
      return $resp;
  }
}

function getContainers($auth, $host, $deployment)
{

  $curl = curl_init();
  $headers = ['Authorization: ' . $auth ];

  curl_setopt_array($curl, array(
      CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_URL => 'https://' . $host . '/api/v2/deployments/' . $deployment . '/containers/',
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_SSL_VERIFYHOST => 0,
      CURLOPT_SSL_VERIFYPEER => 0,
      CURLOPT_FAILONERROR => 1
  ));

  // Send the request & save response to $resp
  $resp = curl_exec($curl);

  if($errno = curl_errno($curl)) {
      $error_message = curl_strerror($errno);
      curl_close($curl);
      throw new Exception($error_message);
  } else {
      curl_close($curl);
      return $resp;
  }
}

function haproxyCmd($backendName, $container, $oper)
{
  $cmd = 'echo "' . $oper . ' server ' . $backendName . '/' . $container . '"  | /usr/bin/nc -U /tmp/haproxy';
  $resp = shell_exec($cmd);
  /*
  if($resp)
  {
      echo $cmd . "\n";
      echo $resp . "\n";
  } else {
      echo "No response\n";
  }
  */
  return $resp;
}

function killContainer($auth, $host, $container)
{
  $curl = curl_init();
  $headers = ['Authorization: ' . $auth ];

  curl_setopt_array($curl, array(
      CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_URL => 'https://' . $host . '/api/v2/containers/' . $container. '/kill',
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_SSL_VERIFYHOST => 0,
      CURLOPT_SSL_VERIFYPEER => 0,
      CURLOPT_FAILONERROR => 1,
      CURLOPT_POST => 1
  ));

  // Send the request & save response to $resp
  $resp = curl_exec($curl);

  if($errno = curl_errno($curl)) {
      $error_message = curl_strerror($errno);
      curl_close($curl);
      throw new Exception($error_message);
  } else {
      curl_close($curl);
      return $resp;
  }
}
