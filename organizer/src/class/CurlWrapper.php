<?php

class CurlWrapper {
    private $curlHandle;

    public function init($url) {
        $this->curlHandle = curl_init($url);
    }

    public function setOption($option, $value) {
        curl_setopt($this->curlHandle, $option, $value);
    }

    public function execute() {
        return curl_exec($this->curlHandle);
    }

    public function getError() {
        return curl_error($this->curlHandle);
    }

    public function getErrno() {
        return curl_errno($this->curlHandle);
    }

    public function close() {
        curl_close($this->curlHandle);
    }
}
