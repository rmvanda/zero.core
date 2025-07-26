<?php
namespace Zero\Core; 

class ExternalRequest{

    protected $clientid;
    protected $apikey; 

    private static $instance; 

    public static function get($url){
        if(!static::$instance){
            static::$instance = new self(); 
        }
        return static::$instance->curlGet($url); 
    }

    public function curlGet($url, $headers = []) {
        // Initialize cURL session
        $ch = curl_init();
        
        // Set default headers if none provided
        if (empty($headers)) {
            $headers = [
                'User-Agent: Mozilla/5.0 (compatible; PHP cURL)',
                'Accept: application/json'
            ];
        }
        
        // Set cURL options
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); 
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        // Execute the request
        $response = curl_exec($ch);
        

        // Get HTTP status code
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $result = [$httpCode,$response];
        
        // Close cURL session
        curl_close($ch);
        
        return $result;
    }

    public function request($url,array $params = []){
        
        try {
            $curl = curl_init();
            if (FALSE === $curl)
                throw new Exception('Failed to initialize');
            $url = $url."?" . http_build_query($params);
            curl_setopt_array($curl, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,  // Capture response.
                CURLOPT_ENCODING => "",  // Accept gzip/deflate/whatever.
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "GET",
                CURLOPT_HTTPHEADER => array(
                    "authorization: Bearer " . $this->apikey,
                    "cache-control: no-cache",
                ),
            ));
            $response = curl_exec($curl);
            if (FALSE === $response)
                throw new Exception(curl_error($curl), curl_errno($curl));
            $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if (200 != $http_status)
                throw new Exception($response, $http_status);
            curl_close($curl);
        } catch(Exception $e) {
            trigger_error(sprintf(
                'Curl failed with error #%d: %s',
                $e->getCode(), $e->getMessage()),
                E_USER_ERROR);
        }
        return json_decode($response); 
    }
    
}
