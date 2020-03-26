<?php
// getip
// 天气网需自己申请接口替换appid和appsecret
// https://tianqiapi.com/ip?appid= &appsecret= 
// ns1.dnspod.net:6666

function get_onlineip()
{

    $url = 'ns1.dnspod.net:6666';

    $my_curl = curl_init();

    curl_setopt($my_curl, CURLOPT_URL, $url);

    curl_setopt($my_curl, CURLOPT_RETURNTRANSFER, 1);

    $ip = curl_exec($my_curl);

    curl_close($my_curl);

    return $ip;

}

/**
 * 签名助手 2017/11/19
 *
 * Class SignatureHelper
 */
class SignatureHelper
{

    /**
     * 生成签名并发起请求
     *
     * @param $accessKeyId string AccessKeyId (https://ak-console.aliyun.com/)
     * @param $accessKeySecret string AccessKeySecret
     * @param $domain string API接口所在域名
     * @param $params array API具体参数
     * @param $security boolean 使用https
     * @return bool|\stdClass 返回API接口调用结果，当发生错误时返回false
     */
    public function request($accessKeyId, $accessKeySecret, $domain, $params, $security = false)
    {
        $apiParams = array_merge(array(
            "SignatureMethod" => "HMAC-SHA1",
            "SignatureNonce" => uniqid(mt_rand(0, 0xffff), true) . '-liro',
            "SignatureVersion" => "1.0",
            "AccessKeyId" => $accessKeyId,
            "Timestamp" => gmdate("Y-m-d\TH:i:s\Z"),
            "Format" => "JSON",
        ), $params);
        ksort($apiParams);

        $sortedQueryStringTmp = "";
        foreach ($apiParams as $key => $value) {
            $sortedQueryStringTmp .= "&" . $this->encode($key) . "=" . $this->encode($value);
        }

        $stringToSign = "GET&%2F&" . $this->encode(substr($sortedQueryStringTmp, 1));

        $sign = base64_encode(hash_hmac("sha1", $stringToSign, $accessKeySecret . "&", true));

        $signature = $this->encode($sign);

        $url = ($security ? 'https' : 'http') . "://{$domain}/?Signature={$signature}{$sortedQueryStringTmp}";
        // echo $url;
        // echo '</br>';
        try {
            $content = $this->fetchContent($url);
            return json_decode($content);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function encode($str)
    {
        $res = urlencode($str);
        $res = preg_replace("/\+/", "%20", $res);
        $res = preg_replace("/\*/", "%2A", $res);
        $res = preg_replace("/%7E/", "~", $res);
        return $res;
    }

    private function fetchContent($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "x-sdk-client" => "php/2.0.0",
        ));

        if (substr($url, 0, 5) == 'https') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        $rtn = curl_exec($ch);
        if ($rtn === false) {
            trigger_error("[CURL_" . curl_errno($ch) . "]: " . curl_error($ch), E_USER_ERROR);
        }
        curl_close($ch);
        return $rtn;
    }
}

function objtoarr($obj)
{
    $ret = array();
    foreach ($obj as $key => $value) {
        if (gettype($value) == 'array' || gettype($value) == 'object') {
            $ret[$key] = objtoarr($value);
        } else {
            $ret[$key] = $value;
        }
    }
    return $ret;
}

function getRecordList($domain = '', $type = '', $keyword = '')
{
    $accessKeyId = "";
    $accessKeySecret = "";
    $params = [];
    if (isset($domain) && !empty($domain)) {
        $params['DomainName'] = $domain;
    } else {
        return '查询主域名不存在';
    }

    $params['Type'] = $type ? $type : 'A';

    if (isset($keyword) && !empty($keyword)) {
        $params['RRKeyWord'] = $keyword;
    }
    $helper = new SignatureHelper();
    $res = $helper->request(
        $accessKeyId,
        $accessKeySecret,
        "alidns.aliyuncs.com",
        array_merge($params, array(
            "RegionId" => "cn-hangzhou",
            "Action" => "DescribeDomainRecords",
            "Version" => "2015-01-09",
        ))
        // fixme 选填: 启用https
        // ,true
    );
    $recordlist = objtoarr($res);
    return $recordlist['DomainRecords']['Record'];
}

function changeRecord($params)
{
    $accessKeyId = "";
    $accessKeySecret = "";
    $params = $params;
    $helper = new SignatureHelper();
    $res = $helper->request(
        $accessKeyId,
        $accessKeySecret,
        "alidns.aliyuncs.com",
        array_merge($params, array(
            "RegionId" => "cn-hangzhou",
            "Action" => "UpdateDomainRecord",
            "Version" => "2015-01-09",
        ))
        // fixme 选填: 启用https
        // ,true
    );
    $changeRecord = objtoarr($res);
    if ($changeRecord['RecordId'] != $params['RecordId']) {
        return false;
    }
    return true;
}

function getRecordId($recordlist, $subdomain = '')
{
    $recordlist = $recordlist;
    if (isset($recordlist) && !empty($recordlist)) {

        foreach ($recordlist as $key => $value) {

            if ($subdomain === $value['RR']) {
                $record = $value;
            }
        }
        if (!isset($record)) {
            return '查询域名不存在';
        }
        return $record;
    }

}

function updatedomain($domain = '', $subdomain = '')
{
    $updateip = '';
    $newip = get_onlineip();
    $recordlist = getRecordList($domain);
    $record = getRecordId($recordlist, $subdomain);
    $record_id = $record['RecordId'];
    $record_ip = $record['Value'];

    if ($newip !== $record_ip) {
        $params = $record;
        $params['Value'] = $newip;
        $res = changeRecord($params);
        if ($res === true) {
            echo "更新IP成功,当前域名：".$record['RR'].".".$record['DomainName']." IP：".$newip;
        }
    } else {
        echo "无需更新IP,当前域名：".$record['RR'].".".$record['DomainName']." IP：".$record['Value'];
    }

}
updatedomain('domain.com', 'subdomain');
//todo PHP定时任务
