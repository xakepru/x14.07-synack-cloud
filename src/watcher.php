<?php

$clientId = "9d459d2fe49dc3e3f55";
$apiKey = "28ec8f29a93c427e263b3";
$newDropletParams = [
    "size_id" => 66,
    "image_id" => 4199185,
    "region_id" => 6,
    "ssh_key_ids" => 154114,
];
$nginxApiPath = "http://128.199.183.xxx/nginx.php";
//define("MAX_MEM_USAGE", 70); //percent value
$checkNodes = ["128.199.183.xxx"];

class HttpClient {
    public function checkEnv()
    {
        if (!extension_loaded("curl")) {
            return false;
        }
        return true;
    }
    function __construct()
    {
        $goodEnv = $this->checkEnv();
        if (!$goodEnv) {
            throw new RuntimeException("Extension curl not loaded");
        }
    }

    public function request($url)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $data = curl_exec($curl);
        curl_close($curl);
        if (!$data) {
            return false;
        }
        return json_decode($data, JSON_OBJECT_AS_ARRAY);
    }
}

class NodesManager
{
    protected $nodesStorage = "nodes.db";
    protected $checkPath = "/response.php";
    protected $nodes;
    /** @var  DropletsManager */
    protected $dropletsManager;
    /** @var  HttpClient */
    protected $httpClient;

    protected $maxLa = 5;
    protected $maxMemUsage = 70;

    /** @var  NginxApi */
    protected $nginxManager;

    /**
     * @return string
     */
    public function getNodesStorage()
    {
        return $this->nodesStorage;
    }

    /**
     * @param string $nodesStorage
     */
    public function setNodesStorage($nodesStorage)
    {
        $this->nodesStorage = $nodesStorage;
    }

    /**
     * @return string
     */
    public function getCheckPath()
    {
        return $this->checkPath;
    }

    /**
     * @return int
     */
    public function getMaxLa()
    {
        return $this->maxLa;
    }

    /**
     * @param int $maxLa
     */
    public function setMaxLa($maxLa)
    {
        $this->maxLa = $maxLa;
    }

    /**
     * @return int
     */
    public function getMaxMemUsage()
    {
        return $this->maxMemUsage;
    }

    /**
     * @param int $maxMemUsage
     */
    public function setMaxMemUsage($maxMemUsage)
    {
        $this->maxMemUsage = $maxMemUsage;
    }

    /**
     * @param string $checkPath
     */
    public function setCheckPath($checkPath)
    {
        $this->checkPath = $checkPath;
    }

    /**
     * @return DropletsManager
     */
    public function getDropletsManager()
    {
        return $this->dropletsManager;
    }

    /**
     * @param DropletsManager $dropletsManager
     */
    public function setDropletsManager($dropletsManager)
    {
        $this->dropletsManager = $dropletsManager;
    }

    /**
     * @return HttpClient
     */
    public function getHttpClient()
    {
        if (is_null($this->httpClient)) {
            $this->httpClient = new HttpClient();
        }
        return $this->httpClient;
    }

    /**
     * @param HttpClient $httpClient
     */
    public function setHttpClient($httpClient)
    {
        $this->httpClient = $httpClient;
    }

    /**
     * @return NginxApi
     */
    public function getNginxManager()
    {
        return $this->nginxManager;
    }

    /**
     * @param NginxApi $nginxManager
     */
    public function setNginxManager($nginxManager)
    {
        $this->nginxManager = $nginxManager;
    }

    protected function loadNodes()
    {
        $nodesStorage = $this->getNodesStorage();
        if (!is_readable($nodesStorage)) {
            throw new \Exception("File \"{$nodesStorage}\" with nodes DB not readable");
        }
        $data = file($nodesStorage, FILE_IGNORE_NEW_LINES);
        if (empty($data)) {
            throw new \Exception("Empty file \"{$nodesStorage}\" with nodes DB");
        }
        $this->nodes = $data;
    }

    public function setNodes(array $nodes)
    {
        $this->nodes = $nodes;
    }

    public function getNodes()
    {
        if (is_null($this->nodes)) {
            $this->loadNodes();
        }
        return $this->nodes;
    }

    public function getNodeStatus($host)
    {
        $url = "http://" . $host . $this->getCheckPath() . "?now=" . time();
        $data = $this->getHttpClient()->request($url);
        return $data;
    }

    public function isNodeOverLoad($host)
    {
        $nodeInfo = $this->getNodeStatus($host);
        if (!$nodeInfo) {
            throw new RuntimeException("Error in get overload info for {$host}");
        }
        return $nodeInfo["LA"] >= $this->getMaxLa() || $nodeInfo["memUsedActivePercent"] >= $this->getMaxMemUsage();
    }

    public function addNode()
    {
        $dropletId =  $this->getDropletsManager()->createDroplet();
        if (!$dropletId) {
            return false;
        }
        $dropletStart = $this->getDropletsManager()->startDroplet($dropletId);
        if (!$dropletStart) {
            return false;
        }

        $i = 0;
        do {
            $i++;
            $dropletInfo = $this->getDropletsManager()->getDropletInfo($dropletId);
        } while($i<100 && (!isset($dropletInfo["droplet"]["ip_address"]) || empty($dropletInfo["droplet"]["ip_address"])));

        $ip = $dropletInfo["droplet"]["ip_address"];
        return $ip;
    }

    public function checkNodes()
    {
        $nodes = $this->getNodes();
        $newIps = [];
        foreach ($nodes as $host) {
            $isNodeOverLoad = $this->isNodeOverLoad($host);
            if ($isNodeOverLoad) {
                $newNodeIp = $this->addNode();
                if (empty($newNodeIp)) {
                    error_log("new node not created");
                    break;
                }
                echo "created new node, ip: {$newNodeIp}\n";
                $newIps[] = $newNodeIp;
            }
        }
        if (!empty($newIps)) {
            $nginxManager = $this->getNginxManager();
            $result = $nginxManager->setBackends($newIps);
            echo ($result && $result["status"]==="OK") ? "set new backends \n" : "error in set new backends: " . var_export($result, true)."\n";
        } else {
            echo "nothing to do\n";
        }
    }
}

class DropletsManager
{
    protected $clientId;
    protected $apiKey;
    protected $apiPath = "https://api.digitalocean.com";
    /**
     * @var HttpClient
     */
    protected $httpClient;

    /** @var  array */
    protected $newDropletParams;

    /**
     * @return string
     */
    public function getApiPath()
    {
        return $this->apiPath;
    }

    /**
     * @param string $apiPath
     */
    public function setApiPath($apiPath)
    {
        $this->apiPath = $apiPath;
    }

    /**
     * @return mixed
     */
    public function getClientId()
    {
        return $this->clientId;
    }

    /**
     * @param mixed $clientId
     */
    public function setClientId($clientId)
    {
        $this->clientId = $clientId;
    }

    /**
     * @return mixed
     */
    public function getApiKey()
    {
        return $this->apiKey;
    }

    /**
     * @param mixed $clientKey
     */
    public function setApiKey($clientKey)
    {
        $this->apiKey = $clientKey;
    }

    /**
     * @return HttpClient
     */
    public function getHttpClient()
    {
        if (is_null($this->httpClient)) {
            $this->httpClient = new HttpClient();
        }
        return $this->httpClient;
    }

    /**
     * @param HttpClient $httpClient
     */
    public function setHttpClient($httpClient)
    {
        $this->httpClient = $httpClient;
    }

    /**
     * @return array
     */
    public function getNewDropletParams()
    {
        return $this->newDropletParams;
    }

    /**
     * @param array $newParams
     */
    public function setNewDropletParams($newParams)
    {
        $this->newDropletParams = $newParams;
    }

    public function sendRequest($actionStr, array $params = [])
    {
        $apiPath = $this->getApiPath();
        $clientId = $this->getClientId();
        $apiKey = $this->getApiKey();
        $paramsStr = "";
        if(!empty($params)) {
            $paramsStr = "&" . http_build_query($params);
        }
        $url = "{$apiPath}/v1/{$actionStr}/?client_id={$clientId}&api_key={$apiKey}{$paramsStr}";
        return $this->getHttpClient()->request($url);
    }

    public function getDroplets()
    {
        return $this->sendRequest("droplets");
    }

    public function getDropletInfo($dropletId)
    {
        return $this->sendRequest("droplets/{$dropletId}");
    }

    public function getNewDropletName()
    {
        return "autoscale-XXX";
    }

    public function createDroplet()
    {
        $params = $this->getNewDropletParams();
        $params["name"] = $this->getNewDropletName();
        $result =  $this->sendRequest("droplets/new", $params);
        if ($result["status"] !== "OK") {
            error_log("New droplet not created [" . var_export($result, true) . "]");
            return false;
        }
        return $result["droplet"]["id"];
    }

    public function startDroplet($dropletId)
    {
        $url = "droplets/{$dropletId}/power_on";
        return $this->sendRequest($url);
    }
}

class NginxApi {
    protected $apiPath;
    /** @var  HttpClient */
    protected $httpClient;

    /**
     * @return HttpClient
     */
    public function getHttpClient()
    {
        if (is_null($this->httpClient)) {
            $this->httpClient = new HttpClient();
        }
        return $this->httpClient;
    }

    /**
     * @param mixed $httpClient
     */
    public function setHttpClient($httpClient)
    {
        if (is_null($this->httpClient)) {
            $this->httpClient = new HttpClient();
        }
        $this->httpClient = $httpClient;
    }

    /**
     * @return mixed
     */
    public function getApiPath()
    {
        return $this->apiPath;
    }

    /**
     * @param mixed $apiPath
     */
    public function setApiPath($apiPath)
    {
        $this->apiPath = $apiPath;
    }

    public function setBackends(array $backends)
    {
        array_unshift($backends, "localhost:8080");
        $backends = implode(",", $backends);
        $backends = urlencode($backends);
        $uri = $this->getApiPath() . "?backends=" . $backends;
        $result = $this->getHttpClient()->request($uri);
        if (!$result) {
            return false;
        }
        return $result;
    }
}

//init
$httpClient = new HttpClient();
$dropletsManager = new DropletsManager();
$dropletsManager->setClientId($clientId);
$dropletsManager->setApiKey($apiKey);
$dropletsManager->setNewDropletParams($newDropletParams);
$dropletsManager->setHttpClient($httpClient);

$nginxApi = new NginxApi();
$nginxApi->setApiPath($nginxApiPath);
$nginxApi->setHttpClient($httpClient);

$nodesManager = new NodesManager();
$nodesManager->setDropletsManager($dropletsManager);
$nodesManager->setNginxManager($nginxApi);
if (defined("MAX_MEM_USAGE")) {
    $nodesManager->setMaxMemUsage(MAX_MEM_USAGE);
}
$nodesManager->setHttpClient($httpClient);
$nodesManager->setNodes($checkNodes);

//do job
$nodesManager->checkNodes();
