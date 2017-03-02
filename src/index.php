<?php

// Mika Rinne ORACLE

$token = $_POST['token'];
$host = $_POST['host'];
$deployment = $_POST['deployment'];
$filter = $_POST['filter'];

if(!$token || !$host || !$deployment)
{
  http_response_code(400);
  echo "Please use POST parameters: authorization host deployment [filter]\n";
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
    echo $deployment . " not found.\n";
  } else {
    $ret = getContainers($auth, $host, $foundDeployment);
    $obj = json_decode($ret, true);
    $containers = $obj['containers'];
    if(count($containers) == 0)
    {
      http_response_code(404);
      echo "No containers found.\n";
    } else {
      $ret = recycle($auth, $host, $foundDeployment, $containers, $filter);
      echo $ret . "\n";
    }
  }
} catch (Exception $e) {
   http_response_code(400); // set HTTP status code BAD_REQUEST
   echo $e->getMessage() . "\n";
}

function recycle($auth, $host, $deployment, $containers, $filter)
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

  foreach($filteredContainers as $container)
  {
    $recycled = false;
    killContainer($auth, $host, $container['container_id']);
    $i = 0;
    while(!$recycled && $i < 20) // EXIT AFTER 1 minute FOR SAFETY
    {
      $i++;
      sleep(3);
      $ret = getContainers($auth, $host, $deployment);
      $obj = json_decode($ret, true);
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
      throw new Exception("Timeout");
    }
  }
  if($filter)
  {
    return "All " . $filter . " recycled.";
  } else {
      return "All recycled.";
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
