<?php
/**
 * Author:: JoeZ99 (<jzarate@gmail.com>).
 *
 * License:: Apache License, Version 2.0
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may
 * not use this file except in compliance with the License. You may obtain
 * a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
 * License for the specific language governing permissions and limitations
 * under the License.
 */
require_once dirname(__FILE__).'/errors.php';


/**
 * Class to manage the connection with the API servers.
 *
 * Needs APIKEY in initialization.
 * Example: $dma = new DoofinderManagementApi('eu1-d531af87f10969f90792a4296e2784b089b8a875')
 */
class DoofinderManagementApi
{
  const REMOTE_API_ENDPOINT = "https://%s-api.doofinder.com/v1";
  const LOCAL_API_ENDPOINT = "http://localhost:8000/api/v1";

  private $apiKey = null;
  private $clusterRegion = 'eu1';
  private $token = null;
  private $baseManagementUrl = null;

  function __construct($apiKey, $local = false){
    $this->apiKey = $apiKey;
    $clusterToken = explode('-', $apiKey);
    $this->clusterRegion = $clusterToken[0];
    $this->token = $clusterToken[1];
    if ($local === true){
      $this->baseManagementUrl = self::LOCAL_API_ENDPOINT;
    } else {
      $this->baseManagementUrl = sprintf(self::REMOTE_API_ENDPOINT, $this->clusterRegion);
    }
  }

  /**
   * Makes the actual request to the API server and normalize response
   *
   * @param string $method The HTTP method to use. 'GET|PUT|POST|DELETE'
   * @param string $entryPoint The path to use. '/<hashid>/items/product'
   * @param array $params If any, url request parameters
   * @param array $data If any, body request parameters
   * @return array Array with both status code and response .
   */
  function managementApiCall($method='GET', $entryPoint='', $params=null, $data=null){
    $headers = array('Authorization: Token '.$this->token, // for Auth
                     'Content-Type: application/json',
                     'Expect:'); // Fixes the HTTP/1.1 417 Expectation Failed

    $url = $this->baseManagementUrl.'/'.$entryPoint;
    if (is_array($params) && sizeof($params) > 0){
      $url .= '?'.http_build_query($params);
    }

    $session = curl_init($url);
    curl_setopt($session, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($session, CURLOPT_HEADER, false);
    curl_setopt($session, CURLOPT_RETURNTRANSFER, true); // Tell curl to return the response
    curl_setopt($session, CURLOPT_HTTPHEADER, $headers); // Adding request headers

    if (in_array($method, array('POST', 'PUT'))){
      curl_setopt($session, CURLOPT_POSTFIELDS, $data);
    }

    $response = curl_exec($session);
    $httpCode = curl_getinfo($session, CURLINFO_HTTP_CODE);
    curl_close($session);

    handleErrors($httpCode, $response);

    $return = array('statusCode' => $httpCode);
    $return['response'] = ($decoded = json_decode($response, true)) ? $decoded : $response;

    return $return;
  }

  /**
   * To get info on all possible api entry points
   * @return array An assoc. array with the different entry points
   */
  function getApiRoot(){
    $response = $this->managementApiCall();
    return $response['response'];
  }

  /**
   * Obtain a list of SearchEngines objects, ready to interact with the API
   * @return array list of searchEngines objects
   */
  function getSearchEngines(){
    $searchEngines = array();
    $apiRoot = $this->getApiRoot();
    unset($apiRoot['searchengines']);

    foreach($apiRoot as $hashid => $props){
      $searchEngines[] = new SearchEngine($this, $hashid, $props['name']);
    }

    return $searchEngines;
  }

}

/**
 * Class with all the capabilities described in the API
 *
 * see http://www.doofinder.com/developer/topics/api/management-api
 */
class SearchEngine {
  public $name = null;
  public $hashid = null;

  private $dma = null; // DoofinderManagementApi instance

  function __construct($dma, $hashid, $name){
    $this->name = $name;
    $this->hashid = $hashid;
    $this->dma = $dma;
  }

  /**
   * Get a list of searchengine's types
   *
   * @return array list of types
   */
  function getDatatypes(){
    return $this->getTypes();
  }

  /**
   * Get a list of searchengine's types
   *
   * @return array list of types
   */
  function getTypes(){
    $result = $this->dma->managementApiCall('GET', "{$this->hashid}/types");
    return $result['response'];
  }

  /**
   * Add a type to the searchengine
   *
   * @param string $dType the type name
   * @return new list of searchengine's types
   */
  function addType($dType){
    $result = $this->dma->managementApiCall('POST', "{$this->hashid}/types", null,
                                            json_encode(array('name' => $dType)));
    return $result['response'];
  }

  /**
   * Delete a type and all its items. HANDLE WITH CARE
   *
   * @param string $dType the Type to delete. All items belonging
   *                          to that type will be removed. mandatory
   * @return boolean true on success
   */
  function deleteType($dType){
    $result = $this->dma->managementApiCall('DELETE', "{$this->hashid}/types/{$dType}");
    return $result['statusCode'] == 204;
  }

  /**
   * Get paginated indexed items belonging to a searchengine's type
   *
   * It only paginates forward. Can't go backwards
   * @param string $dType Type of the items to list
   * @param string $scrollId identifier of the pagination set
   * @return array Assoc array with scroll_id ,paginated results and total results.
   */
  public function getScrolledItemsPage($dType, $scrollId = null){
    $params = $scrollId ? array("scroll_id" => $scrollId) : null;
    $result = $this->dma->managementApiCall('GET', "{$this->hashid}/items/{$dType}", $params);
    return array(
      'scroll_id' => $result['response']['scroll_id'],
      'results' => $result['response']['results'],
      'total' => $result['response']['count'],
    );
  }

  function items($dType){
    return new ItemsRS($this, $dType);
  }

  /**
   * Get details of a specific item
   *
   * @param string $dType Type of the item.
   * @param string $itemId the id of the item
   * @return array Assoc array representing the item.
   */
  function getItem($dType, $itemId){
    $result = $this->dma->managementApiCall('GET', "{$this->hashid}/items/{$dType}/{$itemId}");
    return $result['response'];
  }

  /**
   * Add an item to the search engine
   *
   *   - If the 'id' field is present, use that as item's id or overwrite an existing
   *     item with that id.
   *   - It the 'id' field is not present, create one.
   *
   * @param string $dType type of the. If not provided, first available type is used
   * @param array $itemDescription Assoc array representation of the item
   * @return string the id of the item just created
   */
  function addItem($dType, $itemDescription){
    $result = $this->dma->managementApiCall('POST', "{$this->hashid}/items/{$dType}", null,
                                            json_encode($itemDescription));
    return $result['response']['id'];
  }

  /**
   * Add items in bulk to the search engine
   *
   * For each item:
   *   - If the 'id' field is present, use that as item's id or overwrite an existing
   *     item with that id.
   *   - It the 'id' field is not present, create one.
   *
   * @param string $dType type of the. If not provided, first available type is used
   * @param array $itemsDescription List of Assoc array representation of the item
   * @return array List of ids of the added items
   */
  function addItems($dType, $itemsDescription){
    $result = $this->dma->managementApiCall('POST', "{$this->hashid}/items/{$dType}", null,
                                            json_encode($itemsDescription));

    function fetchId($item){
      return $item['id'];
    };

    return array_map('fetchId', $result['response']);
  }

  /**
   * Update or create an item of the search engine
   *
   * In case of conflict between itemDescription's id or $itemId,
   * the latter is used.
   *
   * @param string $dType  type of the Item.
   * @param string $itemId  Id of the item to be updated/added
   * @param array $itemDescription Assoc array representating the item.
   * @return boolean true on success.
   */
  function updateItem($dType, $itemId, $itemDescription){
    $result = $this->dma->managementApiCall('PUT', "{$this->hashid}/items/{$dType}/{$itemId}", null,
                                            json_encode($itemDescription));
    return $result['statusCode'] == 200;
  }

  /**
   * Bulk update of several items
   *
   * Each item description must contain the 'id' field
   *
   * @param string $dType type of the items.
   * @param array $itemsDescription List of assoc array representing items
   * @return boolean true on success
   */
  function updateItems($dType, $itemsDescription){
    $result = $this->dma->managementApiCall('PUT', "{$this->hashid}/items/{$dType}", null,
                                            json_encode($itemsDescription));
    return $result['statusCode'] == 200;
  }

  /**
   * Delete an item
   *
   * @param string $dType type of the item
   * @param string $itemId id of the item
   * @return boolean true if success, false if failure
   */
  function deleteItem($dType, $itemId){
    $result = $this->dma->managementApiCall('DELETE', "{$this->hashid}/items/{$dType}/{$itemId}");
    return $result['statusCode'] == 204 ;
  }

  /**
   * Ask the server to process the search engine's feeds
   *
   * @return array Assoc array with:
   *               - 'task_created': boolean true if a new task has been created
   *               - 'task_id': if task created, the id of the task.
   */
  function process(){
    $result = $this->dma->managementApiCall('POST', "{$this->hashid}/tasks/process");
    $taskCreated = ($result['statusCode'] == 201);
    $taskId = $taskCreated ? obtainId($result['response']['link']) : null;
    return array('task_created'=>$taskCreated, 'task_id' => $taskId);
  }

  /**
   * Obtain info of the last processing task sent to the server
   *
   * @return array Assoc array with 'state' and 'message' indicating status of the
   *               last asked processing task
   */
  function processInfo(){
    $result = $this->dma->managementApiCall('GET', "{$this->hashid}/tasks/process");
    unset($result['response']['task_name']);
    return $result['response'];
  }

  /**
   * Obtain info about how a task is going or its result
   *
   * @return array Assoc array with 'state' and 'message' indicating the status
   *               of the task
   */
  function taskInfo($taskId){
    $result = $this->dma->managementApiCall('GET', "{$this->hashid}/tasks/{$taskId}");
    unset($result['response']['task_name']);
    return $result['response'];
  }

  /**
   * Obtain logs of the latest feed processing tasks done
   *
   * @return array list of arrays representing the logs
   */
  function logs(){
    $result = $this->dma->managementApiCall("GET", "{$this->hashid}/logs");
    return $result['response'];
  }
}

/**
 * Helper class to iterate through the search engine's items
 *
 * Implemets Iterator interface so foreach() can work with ItemRS
 */
class ItemsRS implements Iterator {

  private $searchEngine = null;
  private $resultsPage = null;
  private $scrollId = null;
  private $position = 0;
  private $total = null;

  function __construct($searchEngine, $dType){
    $this->dType = $dType;
    $this->searchEngine = $searchEngine;
  }

  private function fetchResults(){
    $apiResults = $this->searchEngine->getScrolledItemsPage($this->dType, $this->scrollId);
    $this->total = $apiResults['total'];
    $this->resultsPage = $apiResults['results'];
    $this->scrollId = $apiResults['scroll_id'];
    $this->currentItem = each($this->resultsPage);
  }

  function rewind(){
    $this->scrollId = null;
    $this->fetchResults();
  }

  function valid(){
    return $this->position < $this->total;
  }

  function current(){
    return $this->currentItem['value'];
  }

  function key(){
    return $this->position;
  }

  function next(){
    ++$this->position;
    $this->currentItem = each($this->resultsPage);
    if (!$this->currentItem && $this->position < $this->total){
      $this->fetchResults();
      reset($this->resultsPage);
      $this->currentItem = each($this->resultsPage);
    }
  }
}

/**
 * Extracts identificator from an item or task url.
 *
 * @param string $url item or task resource locator
 * @return the item identificator
 */
function obtainId($url){
  preg_match('~/\w{32}/(items/\w+|tasks)/([\w-_]+)/?$~', $url, $matches);
  return $matches[2];
}
