<?php 
/** 
 * 并发异步采集者 
 */ 
class LCatcher {
    //以下是需要配置的运行参数 
    public $timeout=10;    //默认的超时设置 10 秒 
    public $useProxy=true;    //是否使用代理服务器 
    public $concurrentNum=20;        //并发数量 
    public $autoUserAgent=true;        //是否自动更换UserAgent 
    public $autoFollow=false; //是否自动301/302跳转 
     
    /** 
     * 创建一个采集者 
     * @param number $timeout 超时 
     * @param number $concurrentNum 并发数 
     * @param string $useProxy 是否使用代理 
     */ 
    public function __construct($timeout=10,$concurrentNum=20,$useProxy=true,$autoFollow=false,$autoUserAgent=true){ 
        $this->timeout=$timeout; 
        $this->concurrentNum=$concurrentNum; 
        $this->useProxy=$useProxy; 
        $this->autoFollow=$autoFollow; 
        $this->autoUserAgent=$autoUserAgent; 
    } 
     
    /** 
     * 串行采集 
     * 
     * @param unknown $url 
     *            要采集的地址 
     * @param string $must 
     *            是否肯定对方一定存在 (200,且有</html>) 
     * @param string $referer             
     * @return multitype:NULL mixed 阻塞当前进程直到采集到数据 
     */ 
    public function get($url, $must = true, $iconv = true, $referer = false) { 
        $url = trim ( $url ); 
        static $lastUrl; 
        echo "\r\nURL : $url\r\n"; 
         
        if ($referer === true) { 
            $referer = $lastUrl; 
        } elseif (! $referer) { 
            $referer = ''; 
        } 
         
        //直到成功或放弃 
        while ( true ) { 
            list ( $ch, $proxy ) = $this->createHandle ( $url, $referer ); 
             
            // 开始抓取 
            $begin = microtime ( true ); 
            $content = curl_exec ( $ch ); 
            $code = curl_getinfo ( $ch, CURLINFO_HTTP_CODE ); 
            $end = microtime ( true ); 
            $errno = curl_errno ( $ch ); // 错误编号 
            $error = curl_error ( $ch ); //错误 信息 
             
            // 关闭连接 
            curl_close ( $ch ); 
             
            // 出错,应该是代理的问题,源站不可能出现此情况 
            if ($errno or $code >= '500' or ! $content or $code == '400' or $code == '403' or $code == '401' or $code == '408' or $code == '407') { 
                // 此代理标记失败 
                if ($this->useProxy) { 
                    LProxy::failure ( $proxy); 
                } 
                 
                // 显示错误信息 
                if ($errno) { 
                    if ($errno == 28) { 
                        $error = 'timeout of ' . $this->timeout . 's'; 
                    } 
                    echo "\r\nProxy : $proxy\r\n"; 
                    echo "Curl error : $errno ($error)\r\n"; 
                    continue; 
                } 
                 
                if ($code >= '500') { 
                    echo "\r\nProxy : $proxy\r\n"; 
                    echo "Http Code : $code\r\n"; 
                    continue; 
                } 
            } 
             
            if ($must and ($code != 200 or ! strpos ( $content, '</html>' ))) { 
                if ($code != 200) { 
                    echo "\r\nProxy : $proxy\r\n"; 
                    echo "Http Code : $code\r\n"; 
                    continue; 
                } 
                if (! strpos ( $content, '</html>' )) { 
                    echo "\r\nProxy : $proxy\r\n"; 
                    echo "Not End ,Length: " . strlen ( $content ) . "\r\n"; 
                    continue; 
                } 
            } 
             
            //成功 
            break; 
        } 
         
        // 本次抓取成功 
        if ($this->useProxy) { 
            LProxy::success (); 
        } 
         
        if ($iconv) { 
            $content = self::iconv ( $content ); 
        } 
        echo 'Http Code : ' . $code . " \tUsed : " . round ( $end - $begin, 2 ) . " \tLength : " . strlen ( $content ) . "\r\n"; 
         
        $lastUrl = $url; 
         
        // 返回结果 
        return array ( 
                $code, 
                $content 
        ); 
    } 
     
    //任务栈,可划分优先级,0最高 
    private $jobStack = array (); 
     
    /** 
     * 添加一个异步任务 
     * @param 回调对象 $obj 必须有callback方法 
     * @param 采集地址 $url 
     * @param number $major 优先级 ,0 最高 
     * @param string $iconv 是否转编码 (gb->utf8) 
     * @param string $referer 是否指定了REFERER 
     */ 
    public function pushJob($obj, $url, $major = 0, $iconv = true, $referer = false) { 
        $major = max(99,intval ( $major )); 
        if (! isset ( $this->jobStack [$major] )) { 
            $this->jobStack [$major] = array (); 
        } 
        $this->jobStack [$major] [] = array ( 
                'obj' => $obj, 
                'url' => $url, 
                'iconv' => $iconv, 
                'referer' => $referer 
        ); 
        return $this; 
    } 
     
    //正在采集的句柄集 
    private $map=array(); 
     
    //总采集句柄 
    private $chs; 
     
    /** 
     * 创建一个抓取句柄 
     * @param unknown $url 要抓取的地址 
     * @param string $referer 
     * @return multitype:resource Ambigous <string, null/一维无键数组, boolean, multitype:unknown > 
     */ 
    private function createHandle($url,$referer=''){ 
        //构造一个句柄 
        $ch = curl_init ( $url ); 
             
        //构造配置 
        $opt=array ( 
                CURLOPT_RETURNTRANSFER => true, // 要求返回结果 
                CURLOPT_TIMEOUT => $this->timeout, // 超时 
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, // http 1.1 协议 
                CURLOPT_REFERER => $referer, // 上一次页面 
                CURLOPT_COOKIE => '', // COOKIE 无 
                CURLOPT_FOLLOWLOCATION => $this->autoFollow, // 是否自动 301/302跳转 
                CURLOPT_USERAGENT=> $this->autoUserAgent? $this->agents [rand ( 0, count ( $this->agents ) - 1 )]:'', // 是否随机取一个用户AGENT 
                     
                // 以下是Header,用FireBug之类的抓取一个正常请求的Header数据就可以 
                CURLOPT_HTTPHEADER => array ( 
                        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*" . "/*;q=0.8", 
                        "Accept-Language:zh-CN,zh;q=0.8", 
                        "Connection:keep-alive" 
                ) 
        ); 
             
        // 设置CURL参数 
        curl_setopt_array ( $ch, $opt); 
             
        // 判断是否使用代理服务器 
        if ($this->useProxy) { 
            $proxy = LProxy::get (); 
            if(!$proxy){ 
                dump('no valid proxy');exit; 
            } 
            curl_setopt ( $ch, CURLOPT_PROXY, $proxy ); 
        } else { 
            $proxy = ''; 
        } 
        return array($ch,$proxy); 
    } 
     
    /** 
     * 从待采集任务栈中取任务,加入正在采集的任务集 
     */ 
    private function fillMap(){ 
        //从待处理列表中取信息到正在处理的列表中 
        while(count($this->map)<$this->concurrentNum){ 
            $job=false; 
             
            //从高优先级开始取 
            $keys=array_keys($this->jobStack); 
            sort($keys); 
            foreach($keys as $i){ 
                if(!isset($this->jobStack[$i]) or !count($this->jobStack[$i])){ 
                    continue; 
                } 
                $job=array_pop($this->jobStack[$i]); 
            } 
             
            //已经没有待处理的任务 
            if(!$job){ 
                break; 
            } 
             
            list($ch,$proxy)=$this->createHandle($job['url'],$job['referer']); 
             
            $job['proxy']=$proxy; 
            //加到总句柄中 
            curl_multi_add_handle($this->chs, $ch); 
                 
            //记录到正在处理的句柄中 
            $this->map[strval($ch)] = $job; 
        } 
        return ; 
    } 
     
    /** 
     * 处理一个已经采集到的任务 
     * @param unknown $done 
     */ 
    private function done($done){ 
        $ch=$done['handle']; //子采集句柄 
        $errno=curl_errno($ch); //错误编号 
        $error=curl_error($ch); //错误 信息 
        $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); //HTTP CODE 
         
        //从正在运行的任务集中取出 
        $job = $this->map[strval($ch)]; 
         
        //页面的URL 
        $url=$job['url']; 
         
        //采集过程中的信息 
        $chInfo = curl_getinfo($ch); 
         
        //采集到的内容 
        $result= curl_multi_getcontent($ch); 
         
        //如果任务需要自动 转编码,在此进行 
        if($job['iconv']){ 
            $result= self::iconv($result); 
        } 
         
        //内容的长度 
        $length=strlen($result); 
         
        //出错,应该是代理的问题,源站不可能出现此情况 
        if($errno or $code>='500' or $length==0 or $code=='400' or $code=='403' or $code=='401' or $code=='408' or $code=='407'){ 
            //此代理标记失败 
            if($job['proxy']){ 
                LProxy::failure($job['proxy']); 
            } 
                 
            // 显示错误信息 
            if ($errno) { 
                if ($errno == 28) { 
                    $error = 'timeout of ' . $this->timeout . 's'; 
                } 
                echo "\r\nURL : $url\r\n"; 
                echo "Curl error : $errno ($error)\r\n"; 
            } elseif ($code >= '500') { 
                echo "\r\nURL : $url\r\n"; 
                echo "Http Code : $code\r\n"; 
            } 
                 
            //本信息重新入栈,等待换代理重新执行 
            $this->pushJob($job['obj'],$job['url'],9999); 
                 
            //继续执行 
            return; 
        } 
             
        // 目标网站出错,如: 302,404之类 
        if ($code != 200) { 
            echo "\r\nURL : $url\r\n"; 
            echo "Proxy : " . $job ['proxy'] . "\r\n"; 
            echo "Code : $code Length : $length\r\n"; 
             
            // 调用 回调方法,对采集的内容进行处理 
            $job ['obj']->callback ( $code,$result ); 
             
            // 继续执行 
            return; 
        } 
         
        // 本次抓取成功 
        if($job['proxy']){ 
            LProxy::success ( $job ['proxy'] ); 
        } 
        echo "\r\nURL : $url\r\n"; 
        echo "Http Code : $code \tUsed : " . round ( $chInfo ['total_time'], 2 ) . " \tLength : $length\r\n"; 
         
        // 调用 回调对象的callback方法,对采集的内容进行处理 
        $job ['obj']->callback ($code, $result ); 
    } 
     
    /** 
     * 任务入栈后,开始并发采集 
     */ 
    public function run(){ 
        //总句柄 
        $this->chs = curl_multi_init(); 
         
        //填充任务 
        $this->fillMap(); 
         
        do{//同时发起网络请求,持续查看运行状态 
            do{//如果是正在执行状态,那就继续执行 
                $status = curl_multi_exec($this->chs, $active); 
            }while($status==CURLM_CALL_MULTI_PERFORM); 
             
            //终于有请求完成的子任务(可能多个),逐个取出处理 
            while(true){ 
                $done = curl_multi_info_read($this->chs); 
                if(!$done){ 
                    break; 
                } 
                 
                //对取出的内容进行处理 
                $this->done($done); 
                 
                //去除此任务 
                unset($this->map[strval($done['handle'])]); 
                curl_multi_remove_handle($this->chs, $done['handle']); 
                curl_close($done['handle']); 
                 
                //补充任务 
                $this->fillMap(); 
            };     
             
            //没有任务了,退出吧 
            if (($status != CURLM_OK or !$active) and !count($this->map)){ 
                break; 
            } 
             
            //curl_multi_select ( $this->chs, 0.5 ); // 此处会导致阻塞大概0.5秒。 
        }while(true); //还有句柄处理还在进行中 
    } 
     
    //可以使用的用户代理,随机使用 
    private $agents=array( 
            'Sogou web spider/4.0(+http://www.sogou.com/docs/help/webmasters.htm#07)', 
            'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1; SE 2.X MetaSr 1.0; SE 2.X MetaSr 1.0; .NET CLR 2.0.50727; .NET CLR 3.0.4506.2152; .NET CLR 3.5.30729; .NET CLR 1.1.4322; CIBA; InfoPath.2; SE 2.X MetaSr 1.0; AskTB5.6; SE 2.X MetaSr 1.0)', 
            'ia_archiver (+http://www.alexa.com/site/help/webmasters; crawler@alexa.com)', 
            'Mozilla/5.0 (compatible; YoudaoBot/1.0; http://www.youdao.com/help/webmaster/spider/; )', 
            'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1; Trident/4.0; SV1; .NET CLR 1.1.4322; .NET CLR 2.0.50727; SE 2.X MetaSr 1.0)', 
            'Mozilla/5.0 (Windows NT 6.3; Trident/7.0; rv:11.0) like Gecko', 
            'Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.1; Trident/4.0; SLCC2; .NET CLR 2.0.50727; .NET CLR 3.5.30729; .NET CLR 3.0.30729; Media Center PC 6.0)', 
            'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.1 (KHTML, like Gecko) Chrome/21.0.1180.89 Safari/537.1', 
            'Mozilla/4.0 (compatible; MSIE 7.0; Windows NT 5.1; Trident/4.0; MyIE9; .NET CLR 2.0.50727; InfoPath.1; SE 2.X MetaSr 1.0)', 
            'Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/32.0.1700.76 Safari/537.36', 
            'Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 5.1; Trident/4.0; .NET CLR 2.0.50727)', 
            'Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 5.1; Trident/4.0; InfoPath.2)', 
            'Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 5.1; Trident/4.0; .NET CLR 2.0.50727; .NET CLR 3.0.4506.2152; .NET CLR 3.5.30729)', 
            'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; Trident/4.0; EmbeddedWB 14.52 from: http://www.bsalsa.com/ EmbeddedWB 14.52; InfoPath.3; .NET4.0C; .NET4.0E; .NET CLR 2.0.50727; Shuame; Shuame)', 
            'Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/31.0.1650.63 Safari/537.36 SE 2.X MetaSr 1.0', 
            'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/536.11 (KHTML, like Gecko) Chrome/20.0.1132.11 TaoBrowser/3.5 Safari/536.11', 
            'Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/29.0.1547.66 Safari/537.36 LBBROWSER', 
            'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; .NET CLR 2.0.50727; .NET CLR 1.1.4322; InfoPath.1)', 
            'Mozilla/5.0 (Windows NT 5.1; rv:27.0) Gecko/20100101 Firefox/27.0', 
            'Mozilla/5.0 (compatible; JikeSpider; +http://shoulu.jike.com/spider.html)', 
            'Mozilla/4.0 (compatible; MSIE 6.0b; Windows NT 5.1; DigExt)', 
            'Mozilla/5.0 (compatible; MJ12bot/v1.4.4; http://www.majestic12.co.uk/bot.php?+)', 
            'msnbot-media/1.1 (+http://search.msn.com/msnbot.htm)', 
            'User-Agent: Mozilla/5.0 (compatible; MSIE 6.0;Windows XP)', 
            'Mozilla/5.0 (compatible; CompSpyBot/1.0; +http://www.compspy.com/spider.html)', 
            '360spider-image', 
            'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.21 (KHTML, like Gecko) spider6 Safari/537.21', 
            'NIS Nutch Spider/Nutch-1.7', 
            'User-Agent\x09Baiduspider', 
            'Mozilla/5.0 (compatible; CompSpyBot/1.0; +http://www.compspy.com/spider.html)', 
            'Mozilla/5.0 (compatible; Ezooms/1.0; help@moz.com)', 
            'Mozilla/5.0(compatible;+Sosospider/2.0;++http://help.soso.com/webspider.htm)', 
            'Mozilla/5.0 (compatible; YYSpider; +http://www.yunyun.com/spider.html)', 
            'Mozilla/5.0 (compatible; ZumBot/1.0; http://help.zum.com/inquiry)', 
    ); 
     
    /** 
     * 转编码 GBK=>UTF8 
     * @param unknown $str 
     * @return string|unknown 
     */ 
    static public function iconv($str){ 
        $ret=mb_convert_encoding($str,'UTF-8','gbk'); 
        if($ret){ 
            return $ret; 
        } 
        return $str; 
    } 
     
    static public function mid($content,$begin=false,$end=false){ 
        if($begin!==false){ 
            $b=mb_stripos($content, $begin); 
            if($b===false){ 
                return false; 
            } 
            $content=mb_substr($content, $b+strlen($begin)); 
        } 
        if($end!==false){ 
            $e=mb_stripos($content,$end); 
            if($e===false){ 
                return false; 
            } 
            if($e===0){ 
                $content=''; 
            }elseif($e!==false){ 
                $content=mb_substr($content,0,$e); 
            } 
        } 
        return $content; 
    } 
    /** 
     * 去除硬编码字符 
     * @param unknown $content 
     * @return mixed 
     */ 
    static public function filterHard($content){ 
        $content=preg_replace('/&[^;]*;/','',$content); 
        return $content; 
    } 
     
    /** 
     * 去除所有HTML标签 
     * @param string $content 
     * @return mixed 
     */ 
    static public function filterTag($content){ 
        return preg_replace('/<[^>]*>/', '', $content); 
    } 
     
    /** 
     * 去除HTML注释,IFRAME,脚本,超链接,39的指定标签 
     * @param unknown $content 
     * @return mixed 
     */ 
    static public function filterDetail($content){ 
        $content = preg_replace('/(<\!\-\-.*?\-\->)/sm','',$content); 
        $content=preg_replace( 
                array( 
                        '/<\!\-\-.*?\-\->/sm', 
                        '/<IFRAME.*?<\/IFRAME>/smi', 
                        '/<script.*?<\/script>/smi', 
                        '/<a[^>]*>/mi', 
                        '/<div[^>]*>/mi', 
                        '/<digital39:Content[^>]*>/i', 
                        '/<img alt="" src="http:\/\/www\.weeloo\.com\/[^>]*>/i', 
                        '/<img[^>]*src="http:\/\/www.wtai.cn\/[^>]*>/i', 
                        '/<img[^>]*src="http:\/\/www.aids\-china.com\/[^>]*>/i', 
                ),'',$content 
        ); 
     
        $content=str_replace(array('</a>','</div>','</digital39:Content>',),'',$content); 
        return $content; 
    } 
     
    static public function filterFrameAndScript($content){ 
        for($i=0;$i<2;$i++){ 
            $content= preg_replace( 
                    array( 
                            '/<\!\-\-.*?\-\->/sm', 
                            '/<IFRAME.*?<\/IFRAME>/smi', 
                            '/<script.*?<\/script>/smi', 
                            '/<div[^>]*>\s*<\/div>/im', 
                            '/<style.*?<\/style>/smi', 
                    ),'',$content 
            ); 
        } 
        return $content; 
    } 
}
$this->catcher=new LCatcher(10,20,false,true,true); 
list($code,$content)=$this->catcher->get($url); 
 $this->catch=new LCatcher(10,$asynNum,true);

 $this->catch->pushJob($this,$url,9); 