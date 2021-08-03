<?php
// https://vercel.com/docs/api#endpoints/deployments/create-a-new-deployment


function getpath()
{
    $_SERVER['firstacceptlanguage'] = strtolower(splitfirst(splitfirst($_SERVER['HTTP_ACCEPT_LANGUAGE'],';')[0],',')[0]);
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_FORWARDED_FOR'];
    if (isset($_SERVER['HTTP_FLY_CLIENT_IP'])) $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_FLY_CLIENT_IP'];
    if ($_SERVER['REQUEST_SCHEME']!='http'&&$_SERVER['REQUEST_SCHEME']!='https') {
        if ($_SERVER['HTTP_X_FORWARDED_PROTO']!='') {
            $tmp = explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0];
            if ($tmp=='http'||$tmp=='https') $_SERVER['REQUEST_SCHEME'] = $tmp;
        }
        if ($_SERVER['HTTP_FLY_FORWARDED_PROTO']!='') $_SERVER['REQUEST_SCHEME'] = $_SERVER['HTTP_FLY_FORWARDED_PROTO'];
    }
    $_SERVER['host'] = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'];
    $_SERVER['referhost'] = explode('/', $_SERVER['HTTP_REFERER'])[2];
    if (isset($_SERVER['DOCUMENT_ROOT'])&&$_SERVER['DOCUMENT_ROOT']==='/app') $_SERVER['base_path'] = '/';
    else $_SERVER['base_path'] = path_format(substr($_SERVER['SCRIPT_NAME'], 0, -10) . '/');
    if (isset($_SERVER['UNENCODED_URL'])) $_SERVER['REQUEST_URI'] = $_SERVER['UNENCODED_URL'];
    $p = strpos($_SERVER['REQUEST_URI'],'?');
    if ($p>0) $path = substr($_SERVER['REQUEST_URI'], 0, $p);
    else $path = $_SERVER['REQUEST_URI'];
    $path = path_format( substr($path, strlen($_SERVER['base_path'])) );
    $_SERVER['DOCUMENT_ROOT'] = '/var/task/user';
    return $path;
}

function getGET()
{
    if (!$_POST) {
        if (!!$HTTP_RAW_POST_DATA) {
            $tmpdata = $HTTP_RAW_POST_DATA;
        } else {
            $tmpdata = file_get_contents('php://input');
        }
        if (!!$tmpdata) {
            $postbody = explode("&", $tmpdata);
            foreach ($postbody as $postvalues) {
                $pos = strpos($postvalues,"=");
                $_POST[urldecode(substr($postvalues,0,$pos))]=urldecode(substr($postvalues,$pos+1));
            }
        }
    }
    if (isset($_SERVER['UNENCODED_URL'])) $_SERVER['REQUEST_URI'] = $_SERVER['UNENCODED_URL'];
    $p = strpos($_SERVER['REQUEST_URI'],'?');
    if ($p>0) {
        $getstr = substr($_SERVER['REQUEST_URI'], $p+1);
        $getstrarr = explode("&",$getstr);
        foreach ($getstrarr as $getvalues) {
            if ($getvalues != '') {
                $pos = strpos($getvalues, "=");
            //echo $pos;
                if ($pos > 0) {
                    $getarry[urldecode(substr($getvalues, 0, $pos))] = urldecode(substr($getvalues, $pos + 1));
                } else {
                    $getarry[urldecode($getvalues)] = true;
                }
            }
        }
    }
    if (isset($getarry)) {
        return $getarry;
    } else {
        return [];
    }
}

function getConfig($str, $disktag = '')
{
    if (isInnerEnv($str)) {
        if ($disktag=='') $disktag = $_SERVER['disktag'];
        $env = json_decode(getenv($disktag), true);
        if (isset($env[$str])) {
            if (isBase64Env($str)) return base64y_decode($env[$str]);
            else return $env[$str];
	}
    } else {
        if (isBase64Env($str)) return base64y_decode(getenv($str));
        else return getenv($str);
    }
    return '';
}

function setConfig($arr, $disktag = '')
{
    if ($disktag=='') $disktag = $_SERVER['disktag'];
    $disktags = explode("|",getConfig('disktag'));
    if ($disktag!='') $diskconfig = json_decode(getenv($disktag), true);
    $tmp = [];
    $indisk = 0;
    $operatedisk = 0;
    foreach ($arr as $k => $v) {
        if (isCommonEnv($k)) {
            if (isBase64Env($k)) $tmp[$k] = base64y_encode($v);
            else $tmp[$k] = $v;
        } elseif (isInnerEnv($k)) {
            if (isBase64Env($k)) $diskconfig[$k] = base64y_encode($v);
            else $diskconfig[$k] = $v;
            $indisk = 1;
        } elseif ($k=='disktag_add') {
            array_push($disktags, $v);
            $operatedisk = 1;
        } elseif ($k=='disktag_del') {
            $disktags = array_diff($disktags, [ $v ]);
            $tmp[$v] = '';
            $operatedisk = 1;
        } elseif ($k=='disktag_copy') {
            $newtag = $v . '_' . date("Ymd_His");
            $tmp[$newtag] = getConfig($v);
            array_push($disktags, $newtag);
            $operatedisk = 1;
        } elseif ($k=='disktag_rename' || $k=='disktag_newname') {
            if ($arr['disktag_rename']!=$arr['disktag_newname']) $operatedisk = 1;
        } else {
            $tmp[$k] = json_encode($v);
        }
    }
    if ($indisk) {
        $diskconfig = array_filter($diskconfig, 'array_value_isnot_null');
        ksort($diskconfig);
        $tmp[$disktag] = json_encode($diskconfig);
    }
    if ($operatedisk) {
        if (isset($arr['disktag_newname']) && $arr['disktag_newname']!='') {
            $tags = [];
            foreach ($disktags as $tag) {
                if ($tag==$arr['disktag_rename']) array_push($tags, $arr['disktag_newname']);
                else array_push($tags, $tag);
            }
            $tmp['disktag'] = implode('|', $tags);
            $tmp[$arr['disktag_newname']] = getConfig($arr['disktag_rename']);
            $tmp[$arr['disktag_rename']] = null;
        } else {
            $disktags = array_unique($disktags);
            foreach ($disktags as $disktag) if ($disktag!='') $disktag_s .= $disktag . '|';
            if ($disktag_s!='') $tmp['disktag'] = substr($disktag_s, 0, -1);
            else $tmp['disktag'] = null;
        }
    }
    foreach ($tmp as $key => $val) if ($val=='') $tmp[$key]=null;

    return setVercelConfig($tmp, getConfig('HerokuappId'), getConfig('APIKey'));
    error_log1(json_encode($arr, JSON_PRETTY_PRINT) . ' => tmp：' . json_encode($tmp, JSON_PRETTY_PRINT));
}

function install()
{
    global $constStr;
    if ($_GET['install1']) {
        if ($_POST['admin']!='') {
            $tmp['admin'] = $_POST['admin'];
            //$tmp['language'] = $_POST['language'];
            $tmp['timezone'] = $_COOKIE['timezone'];
            $APIKey = getConfig('APIKey');
            if ($APIKey=='') {
                $APIKey = $_POST['APIKey'];
                $tmp['APIKey'] = $APIKey;
            }
            $HerokuappId = getConfig('HerokuappId');
            if ($HerokuappId=='') {
                $function_name = getConfig('function_name');
                if ($function_name=='') {
                    $tmp1 = substr($_SERVER['HTTP_HOST'], 0, strrpos($_SERVER['HTTP_HOST'], '.'));
                    $maindomain = substr($tmp1, strrpos($tmp1, '.')+1);
                    if ($maindomain=='herokuapp') $function_name = substr($tmp1, 0, strrpos($tmp1, '.'));
                    else return message('Please visit from xxxx.herokuapp.com', '', 500);
                    $res = HerokuAPI('GET', 'https://api.heroku.com/apps/' . $function_name, '', $APIKey);
                    $response = json_decode($res['body'], true);
                    if (isset($response['build_stack'])) {
                        $HerokuappId = $response['id'];
                    } else {
                        return message('Get Heroku app id: ' . json_encode($res, JSON_PRETTY_PRINT), 'Something error', 500);
                    }
                }
            }
            $tmp['HerokuappId'] = $HerokuappId;
            $response = json_decode(setVercelConfig($tmp, $HerokuappId, $APIKey)['body'], true);
            if (api_error($response)) {
                $html = api_error_msg($response);
                $title = 'Error';
            } else {
                return output('Jump
    <script>
        var expd = new Date();
        expd.setTime(expd.getTime()+1000);
        var expires = "expires="+expd.toGMTString();
        document.cookie=\'language=; path=/; \'+expires;
    </script>
    <meta http-equiv="refresh" content="3;URL=' . path_format($_SERVER['base_path'] . '/') . '">', 302);
            }
            return message($html, $title, 201);
        }
    }
    if ($_GET['install0']) {
        $html .= '
    <form action="?install1" method="post" onsubmit="return notnull(this);">
language:<br>';
        foreach ($constStr['languages'] as $key1 => $value1) {
            $html .= '
        <label><input type="radio" name="language" value="'.$key1.'" '.($key1==$constStr['language']?'checked':'').' onclick="changelanguage(\''.$key1.'\')">'.$value1.'</label><br>';
        }
        if (getConfig('APIKey')=='') $html .= '
        <a href="https://vercel.com/account/tokens" target="_blank">' . getconstStr('Create') . ' token</a><br>
        <label>Token:<input name="APIKey" type="text" placeholder="" size=""></label><br>';
        $html .= '<br>
        <label>Set admin password:<input name="admin" type="password" placeholder="' . getconstStr('EnvironmentsDescription')['admin'] . '" size="' . strlen(getconstStr('EnvironmentsDescription')['admin']) . '"></label><br>';
        $html .= '
        <input type="submit" value="'.getconstStr('Submit').'">
    </form>
    <script>
        var nowtime= new Date();
        var timezone = 0-nowtime.getTimezoneOffset()/60;
        var expd = new Date();
        expd.setTime(expd.getTime()+(2*60*60*1000));
        var expires = "expires="+expd.toGMTString();
        document.cookie="timezone="+timezone+"; path=/; "+expires;
        function changelanguage(str)
        {
            var expd = new Date();
            expd.setTime(expd.getTime()+(2*60*60*1000));
            var expires = "expires="+expd.toGMTString();
            document.cookie=\'language=\'+str+\'; path=/; \'+expires;
            location.href = location.href;
        }
        function notnull(t)
        {
            if (t.admin.value==\'\') {
                alert(\'input admin\');
                return false;
            }';
        if (getConfig('APIKey')=='') $html .= '
            if (t.APIKey.value==\'\') {
                alert(\'input API Key\');
                return false;
            }';
        $html .= '
            return true;
        }
    </script>';
        $title = getconstStr('SelectLanguage');
        return message($html, $title, 201);
    }
    $html .= '<a href="?install0">'.getconstStr('ClickInstall').'</a>, '.getconstStr('LogintoBind');
    $title = 'Install';
    return message($html, $title, 201);
}

function setVercelConfig($envs, $appId, $token)
{
	$url = "https://api.vercel.com/v12/now/deployments";
	$header["Authorization"] = "Bearer " . $token;
	$header["Content-Type"] = "application/json";
    // update ! save to file.
}

function api_error($response)
{
    return isset($response['message']);
}

function api_error_msg($response)
{
    return $response['code'] . '<br>
' . $response['message'] . '<br>
<button onclick="location.href = location.href;">'.getconstStr('Refresh').'</button>';
}

function setConfigResponse($response)
{
    return json_decode($response, true);
}

function OnekeyUpate($auth = 'qkqpttgf', $project = 'OneManager-php', $branch = 'master')
{
    $slash = '/';
    if (strpos(__DIR__, ':')) $slash = '\\';
    // __DIR__ is xxx/platform
    $projectPath = splitlast(__DIR__, $slash)[0];

    // 从github下载对应tar.gz，并解压
    $url = 'https://github.com/' . $auth . '/' . $project . '/tarball/' . urlencode($branch) . '/';
    $tarfile = $projectPath . $slash .'github.tar.gz';
    $githubfile = file_get_contents($url);
    if (!$githubfile) return 0;
    file_put_contents($tarfile, $githubfile);
    if (splitfirst(PHP_VERSION, '.')[0] > '5') {
        $phar = new PharData($tarfile); // need php5.3, 7, 8
        $phar->extractTo($projectPath, null, true);//路径 要解压的文件 是否覆盖
    } else {
        ob_start();
        passthru('tar -xzvf ' . $tarfile, $stat);
        ob_get_clean();
    }
    unlink($tarfile);

    $outPath = '';
    $tmp = scandir($projectPath);
    $name = $auth . '-' . $project;
    foreach ($tmp as $f) {
        if ( substr($f, 0, strlen($name)) == $name) {
            $outPath = $projectPath . $slash . $f;
            break;
        }
    }
    //error_log1($outPath);
    if ($outPath=='') return 0;

    //unlink($outPath.'/config.php');
    $response = rename($projectPath . $slash . '.data' . $slash . 'config.php', $outPath . $slash . '.data' . $slash . 'config.php');
    if (!$response) {
        $tmp1['code'] = "Move Failed";
        $tmp1['message'] = "Can not move " . $projectPath . $slash . '.data' . $slash . 'config.php' . " to " . $outPath . $slash . '.data' . $slash . 'config.php';
        return json_encode($tmp1);
    }
    return moveFolder($outPath, $projectPath, $slash);
}

function moveFolder($from, $to, $slash)
{
    if (substr($from, -1)==$slash) $from = substr($from, 0, -1);
    if (substr($to, -1)==$slash) $to = substr($to, 0, -1);
    if (!file_exists($to)) mkdir($to, 0777);
    $handler=opendir($from);
    while($filename=readdir($handler)) {
        if($filename != '.' && $filename != '..'){
            $fromfile = $from . $slash . $filename;
            $tofile = $to . $slash . $filename;
            if(is_dir($fromfile)){// 如果读取的某个对象是文件夹，则递归
                $response = moveFolder($fromfile, $tofile, $slash);
                if (api_error(setConfigResponse($response))) return $response;
            }else{
                //if (file_exists($tofile)) unlink($tofile);
                $response = rename($fromfile, $tofile);
                if (!$response) {
                    $tmp['code'] = "Move Failed";
                    $tmp['message'] = "Can not move " . $fromfile . " to " . $tofile;
                    return json_encode($tmp);
                }
                if (file_exists($fromfile)) unlink($fromfile);
            }
        }
    }
    closedir($handler);
    rmdir($from);
    return json_encode( [ 'response' => 'success' ] );
}
