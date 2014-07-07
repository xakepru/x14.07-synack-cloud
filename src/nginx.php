<?php

/**
 * User: Vasiliy Shvakin (orbisnull) zen4dev@gmail.com
 */
class NginxManager
{
    protected $backendsFile = "/etc/nginx/conf.d/backend.conf";

    /**
     * @return string
     */
    public function getBackendsFile()
    {
        return $this->backendsFile;
    }

    /**
     * @param string $backendsFile
     */
    public function setBackendsFile($backendsFile)
    {
        $this->backendsFile = $backendsFile;
    }

    public function setBackends(array $backends)
    {
        $file = $this->getBackendsFile();
        $fh = fopen($file, "w");
        fwrite($fh, "upstream backend {\n");
        foreach ($backends as $backend) {
            fwrite($fh, "    server " . $backend . ";\n");
        }
        fwrite($fh, "}\n");
        fclose($fh);
    }

    public function reload()
    {
        $output = [];
        $code = 0;
        exec("sudo /usr/sbin/nginx -t 2>&1", $output, $code);
        if ($code ===0 ) {
            $output = [];
            exec("sudo /usr/sbin/nginx -s reload 2>&1", $output, $code);
        }
        return [
            "output" => $output,
            "code" => $code
        ];
    }

    public function processRequest()
    {
        if ($_SERVER["REQUEST_METHOD"] !== "GET" || !isset($_GET["backends"])) {
            echo json_encode(["status" => "ERROR"], JSON_PRETTY_PRINT);
            return;
        }
        $backends = $_GET["backends"];
        $backends = explode(",", $backends);
        $this->setBackends($backends);
        $result = $this->reload();
        if ($result["code"] === 0) {
            echo json_encode(["status" => "OK", "backends" => $backends], JSON_PRETTY_PRINT);
        } else {
            echo json_encode(["status" => "ERROR", "info" => implode("\n", $result["output"])], JSON_PRETTY_PRINT);
        }
    }
}

$nm = new NginxManager();
$nm->processRequest();