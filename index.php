<?php
/**
 * 分析HTML文件列表中 文件的相似度 并替换为 织梦模板标签
 * 文件编码限定 UTF-8
 * 注意文件内容相同是根据 HTML 标签的完全匹配 忽略 <script></script> <style></style> <link />
 * TODO
 * 首先选择替换文件
 * 1、添加常用替换正则表达式
 * 2、改变替换规则为公共部分-人为区分
 * 3、添加选项从头匹配，还是从尾匹配
 * 4、替换是否排除首页
 * 5、head头部标签完美化处理-先替换为空-再添加到编码后边
 *
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(0);  //不限制 执行时间
date_default_timezone_set('Asia/Shanghai');
header("content-Type: text/html; charset=utf-8"); //语言强制
header('Cache-Control:no-cache,must-revalidate');
header('Pragma:no-cache');

//todo 环境检测
//1、PHP版本 默认大于5.3
//2、函数库检测：打开文件夹需要 system 函数

//定义根目录
define('WEB_ROOT', str_replace("\\", '/', dirname(__FILE__)) );
define('INPUT_DIR', WEB_ROOT . '/input/');
define('OUTPUT_DIR', WEB_ROOT . '/output/');
define('VENDOR_DIR', WEB_ROOT . '/vendor/');

//比较HTML字符串中HTML标签的最小开始数量
define('HTML_MIN_COMPARE', 10);
define('LOG_DIFFERENT_HEAD', WEB_ROOT . '/different_head.log');
define('LOG_DIFFERENT_FOOT', WEB_ROOT . '/different_foot.log');

//定义模板常量
define('BR', "\r\n");
define('TEMPLATES_HEAD', "{dede:include filename='HEAD.htm'/}");
define('TEMPLATES_FOOT', "{dede:include filename='FOOTER.htm'/}");
define('HEAD_FILE_PATH', OUTPUT_DIR . 'HEAD.html');
define('FOOT_FILE_PATH', OUTPUT_DIR . 'FOOTER.html');

//清空输出目录 和 日志
deldir(OUTPUT_DIR);
if(file_exists(LOG_DIFFERENT_HEAD)){
    unlink(LOG_DIFFERENT_HEAD);
}
if(file_exists(LOG_DIFFERENT_FOOT)){
    unlink(LOG_DIFFERENT_FOOT);
}
//======================================================================================================================
//======================================================================================================================
//菜单：
echo '<h1><a href="?">1、头部和底部识别替换（默认）</a></h1>';
echo '<h1><a href="?type=2">2、内页公共部分识别显示</a></h1>';

$type = isset($_GET['type']) ? $_GET['type'] : '';

if(2 == $type){
    goto secondStage;
}
//======================================================================================================================
//======================================================================================================================

//HTML 单闭合标签
$html_single_tag = array('br', 'hr', 'area', 'base', 'img', 'input', 'link', 'meta', 'basefont', 'param', 'col', 'frame', 'embed');

//存储运行中的变量
$comprehensive = array();

//获取文件列表
$file_list = get_file_list();

//分析HTML标签并提出来存放
$html_body = '';

foreach($file_list as $key=>$item){
    $html_body = get_file_content($item);
    $html_body = substr($html_body, stripos($html_body, '<body'));
    $html_body = substr($html_body, 0, stripos($html_body, '</body>') + 7);
//    echo $html_body;
//    exit;
    $html_tags = analysis_html($html_body);
//    var_dump($html_tags);
//    echo ($html_tags);
//    echo "\r\n\r\n\r\n";
//    exit;
    $comprehensive[$key]['file_path'] = $item;
    $comprehensive[$key]['html_tags'] = $html_tags;
    $comprehensive[$key]['html_tags_count'] = substr_count($html_tags, '>'); //统计有多少个HTML标签闭合的算2个
}


//比较HTML标签中内容相似程度
//var_dump($comprehensive);
//======================================================================================================================



//======================================================================================================================
//region 比较头部 start
$compare_results = array(); //比较结果
foreach($comprehensive as $key=>$value){

    $count = get_median($value['html_tags_count']);

    for($i=HTML_MIN_COMPARE; $i<=$count; $i++){

        //截取原始比较的HTML标签
        $resorce_str = get_offset_str($value['html_tags'], $i, '>');
        $resorce_str_length = strlen($resorce_str);

        foreach($comprehensive as $k=>$v){
            if($key == $k){
                continue;
            }

            //开始比较
            $target_str = substr($v['html_tags'], 0, $resorce_str_length);

            $compare_results[$key][$i][$k]['source_file_path'] = $value['file_path'];
            $compare_results[$key][$i][$k]['target_file_path'] = $v['file_path'];
            $compare_results[$key][$i][$k]['source_str'] = $resorce_str;
            $compare_results[$key][$i][$k]['target_str'] = $target_str;

            if(strcmp($resorce_str, $target_str) !== 0){

                $compare_results[$key][$i][$k]['result'] = false;
                put_file_content(LOG_DIFFERENT_HEAD, var_export($compare_results[$key][$i][$k], true) . BR);
//                echo var_export($compare_results[$key][$i][$k], true);
            }
            else{
                $compare_results[$key][$i][$k]['result'] = true;
            }

        }

        // 检查一次比较完成 如果都不相同 证明源字符串 已经不是公共部分 直接跳出此源字符串比较
        // 默认前边截取的都是相同的，如果不同则证明 已经不是公共部分 则直接跳出字符串比较
        $is_equals = true;
        foreach($compare_results[$key][$i] as $item){
//            var_dump($item);
            if($item['result'] == false){
                $is_equals = false;
//                put_file_content(WEB_ROOT . '/different.log', $value['file_path']);
                break;
            }
        }

        if($is_equals === false){
            break;
        }
//        var_dump($compare_results);
//        exit;

    }

}

//var_dump($compare_results);
// 选取匹配的结果集
$matches_result = array();

foreach($compare_results as $index=>$item){

    $count = count($item) + HTML_MIN_COMPARE;
//    var_dump($count);
//    $matches_result[$index] = $item[$count - 1];

    if(isset($item[$count - 2])){
        $matches_result[$index] = $item[$count - 2];
    }
    else{
        $matches_result[$index] = $item[$count - 1];
    }

}

//var_dump($matches_result);
// 获取头部匹配HTML标签
$head_html_tags = '';
foreach($matches_result as $index=>$item){

    foreach($item as $key=>$value){
        $head_html_tags_length = strlen($head_html_tags);
        if($head_html_tags_length < strlen($value['source_str'])){
            $head_html_tags = $value['source_str'];
        }
    }
}

//去除多余的HTML TAGS 即 闭合匹配 - 默认多余部分无闭合标签！！！
$head_html_tags = str_ireplace('<body>', '', $head_html_tags);
//echo $head_html_tags;
// 此处有bug 如果第一个标签是资源标签 如 link script style 等等则 匹配错误！
$head_first_tag = substr($head_html_tags, 0, strpos($head_html_tags, '>') + 1);
//echo $head_first_tag;
$head_first_end_tag = '</' . substr($head_first_tag, 1);
$head_html_tags = substr($head_html_tags, 0, strripos($head_html_tags, $head_first_end_tag) + strlen($head_first_end_tag));

//echo $head_html_tags;
// 添加 body 标记确定是头部 或者 正则匹配的第一个结果 ！！！
$head_html_tags = '<body>' . $head_html_tags;
//匹配HTML头部的 正则表达式
$head_pattern = get_html_pattern($head_html_tags);

echo "<h3>匹配头部（HEAD.htm）正则表达式：</h3>";
echo BR;
echo '<pre><xmp>' . $head_pattern . '</xmp></pre>';
//endregion 比较头部 end
//======================================================================================================================

echo BR . BR;

//======================================================================================================================
//region 比较尾部 Start
$compare_results = array(); //比较结果
foreach($comprehensive as $key=>$value){

    $count = get_median($value['html_tags_count']);
//var_dump($count);

    for($i=HTML_MIN_COMPARE; $i<=$count; $i++){
        //截取原始比较的HTML标签
        $resorce_str = get_end_offset_str($value['html_tags'], $i, '<');
//        echo $resorce_str;
//        exit;
        $resorce_str_length = strlen($resorce_str);

        foreach($comprehensive as $k=>$v){
            if($key == $k){
                continue;
            }

            //开始比较
            $target_str = substr($v['html_tags'], -$resorce_str_length);
//            echo $target_str;
//            echo "\r\n\r\n\r\n";
//            exit;
            $compare_results[$key][$i][$k]['source_file_path'] = $value['file_path'];
            $compare_results[$key][$i][$k]['target_file_path'] = $v['file_path'];
            $compare_results[$key][$i][$k]['source_str'] = $resorce_str;
            $compare_results[$key][$i][$k]['target_str'] = $target_str;

            if(strcmp($resorce_str, $target_str) !== 0){
                $compare_results[$key][$i][$k]['result'] = false;
                put_file_content(LOG_DIFFERENT_FOOT, var_export($compare_results[$key][$i][$k], true) . BR);
//                echo var_export($compare_results[$key][$i][$k], true);
            }
            else{
                $compare_results[$key][$i][$k]['result'] = true;
            }

        }

        // 检查一次比较完成 如果都不相同 证明源字符串 已经不是公共部分 直接跳出此源字符串比较
        // 默认前边截取的都是相同的，如果不同则证明 已经不是公共部分 则直接跳出字符串比较
        $is_equals = true;
        foreach($compare_results[$key][$i] as $item){
//            var_dump($item);
            if($item['result'] == false){
                $is_equals = false;
                break;
            }
        }

        if($is_equals === false){
            break;
        }
//        var_dump($compare_results);
//        exit;

    }

}
//var_dump($compare_results);

// 选取匹配的结果集
$matches_result = array();
foreach($compare_results as $index=>$item){

    $count = count($item) + HTML_MIN_COMPARE;
//    var_dump($count);
//    $matches_result[$index] = $item[$count - 1];

    if(isset($item[$count - 2])){
        $matches_result[$index] = $item[$count - 2];
    }
    else{
        $matches_result[$index] = $item[$count - 1];
    }

}

//var_dump($matches_result);
// 获取尾部匹配HTML标签
$foot_html_tags = '';
foreach($matches_result as $index=>$item){

    foreach($item as $key=>$value){
        $foot_html_tags_length = strlen($foot_html_tags);
        if($foot_html_tags_length < strlen($value['source_str'])){
            $foot_html_tags = $value['source_str'];
        }
    }
}

//去除多余的HTML TAGS 即 闭合匹配 - 默认多余部分无闭合标签！！！
//echo $foot_html_tags;
for($i=0, $count=strlen($foot_html_tags); $i<$count; $i++){
//    echo $foot_html_tags[1];
//    echo "\r\n\r\n";
    if('/' == $foot_html_tags[1]){
        $foot_html_tags = substr($foot_html_tags, strpos($foot_html_tags, '>') + 1);
    }
    else{
        break;
    }
}

//echo $foot_html_tags;

$foot_pattern = get_html_pattern($foot_html_tags);

echo "<h3>匹配底部（FOOTER.htm）正则表达式：</h3>";
echo BR;
echo '<pre><xmp>' . $foot_pattern . '</xmp></pre>';
//endregion 比较尾部 End
//======================================================================================================================

echo BR . BR;
echo '<h3>替换文件列表：</h3>';
echo BR . BR;

//替换后输出文件
//======================================================================================================================
foreach($file_list as $key=>$item){
    $html_content = get_file_content($item);

    $html_body = substr($html_content, stripos($html_content, '<body'));
    $html_body = substr($html_body, 0, stripos($html_body, '</body>') + 7);

    // HTML_body 内容 预处理 处理注释 代码 <!-- <div></div> -->
    $matches_notes = array();
    preg_match_all('/<!--[\s\S]*?-->/i', $html_body, $matches_notes);

    if(isset($matches_notes[0][0])){
        foreach($matches_notes[0] as $k=>$v){
            $html_body = str_replace($v, '#!--' . $k . '--#', $html_body);
        }
    }

//    echo $html_body;
    $body_tag = substr($html_body, 0, stripos($html_body, '>') + 1);

//    echo $body_tag;
    // TODO 此处 可以添加替换逻辑 如 <script></script> 脚本不同 则替换为 [\s\S]*?
    //替换头部
    $head_matches = array();
    preg_match($head_pattern, $html_body, $head_matches);
    if(isset($head_matches[0])){
        $head_html = str_replace($body_tag, '', $head_matches[0]);
    }
    else{
        $head_html = 'HEAD';
    }
//    var_dump($head_matches);

    $head_file_path = HEAD_FILE_PATH;
    if(!file_exists($head_file_path)){
        if(isset($matches_notes[0][0])){
            foreach($matches_notes[0] as $k=>$v){
                if(false !== strpos($head_html, '#!--' . $k . '--#')){
                    $head_html = str_replace('#!--' . $k . '--#', $v, $head_html);
                }
            }
        }
        put_file_content($head_file_path, $head_html);
    }

    $html_body = preg_replace($head_pattern, $body_tag . BR . TEMPLATES_HEAD . BR, $html_body);
//echo $html_body; exit;
    //=====================================================================
    //替换底部
    // 2019年6月22日10:01:47 改进正则表达式的bug 从开始全匹配了。如果开始是<script 脚本 则手动截取字符串
    $foot_file_path = FOOT_FILE_PATH;

    if('/<script' == strtolower(substr($foot_pattern, 0, 8))){
//        exit('xslooi');
        //如果第一个是<script 则先用字符串截取函数截取-即不用正则匹配整个body代码段
        $foot_replace_html = '';
        $foot_replace_offset = 0;
        $foot_body_html = $html_body;
        $script_count = substr_count($foot_pattern, '<script');

        for($s_t=0; $s_t<$script_count; $s_t++){
            $foot_replace_offset = strripos($foot_body_html, '<script');
            $foot_replace_html = substr($foot_body_html, $foot_replace_offset) . $foot_replace_html;
            $foot_body_html = substr($foot_body_html, 0, $foot_replace_offset);
//           echo $foot_body_html;
//           echo $foot_replace_html;
//           echo BR . '==================================================' . BR;
        }

        $foot_replace_html = str_ireplace('</body>', '', $foot_replace_html);

        put_file_content($foot_file_path, $foot_replace_html);
        $html_body = str_replace($foot_replace_html, BR . TEMPLATES_FOOT . BR, $html_body);

    }
    else{

        $foot_matches = array();
        preg_match($foot_pattern, $html_body, $foot_matches);
        if(isset($foot_matches[0])){
            $foot_html = str_ireplace('</body>', '', $foot_matches[0]);
        }
        else{
            $foot_html = 'FOOTER';
        }
//    var_dump($head_matches);

        if(!file_exists($foot_file_path)){
            if(isset($matches_notes[0][0])){
                foreach($matches_notes[0] as $k=>$v){
                    if(false !== strpos($foot_html, '#!--' . $k . '--#')){
                        $foot_html = str_replace('#!--' . $k . '--#', $v, $foot_html);
                    }
                }
            }
            put_file_content($foot_file_path, $foot_html);
        }
        $html_body = preg_replace($foot_pattern, BR . TEMPLATES_FOOT . BR . '</body>', $html_body);
    }

//echo $html_body;
    // HTML_body 内容 处理 1、处理注释 注释还原
    if(isset($matches_notes[0][0])){
        foreach($matches_notes[0] as $k=>$v){
            $html_body = str_replace('#!--' . $k . '--#', $v, $html_body);
        }
    }

    $html_content = preg_replace('/<body[\s\S]*<\/body>/', $html_body, $html_content);
//    echo $html_content;
//    exit;
    put_file_content($item, $html_content);
//    echo $html_body;
//    echo $html_content;
//    exit;

    echo '<h5>' . ($key + 1) . ' - ' . iconv('GB2312', 'UTF-8//IGNORE', $item) . '</h5>';
    echo BR;
}

echo BR . '<h2>恭喜，处理完成！</h2>';

//识别 头部底部并替换 退出
exit();
//======================================================================================================================
//======================================================================================================================
//======================================================================================================================
//内部公共内容识别开始
secondStage:

$html_segments_array = array();
// 获取替换完成的文件列表：
$replace_list = get_file_list();
echo BR;
//var_dump($replace_list);

foreach($replace_list as $item){
    //屏蔽头部和底部
    if(false !== stripos($item, 'HEAD') || false !== stripos($item, 'FOOTER')){
       continue;
    }

    echo iconv('GB2312', 'UTF-8//IGNORE', $item);
    echo BR . BR;

    $html_body = get_file_content($item);
    $html_body = substr($html_body, stripos($html_body, '<body'));
    $html_body = substr($html_body, 0, stripos($html_body, '</body>') + 7);

    $html_body = str_replace("{dede:include filename='HEAD.htm'/}", '', $html_body);
    $html_body = str_replace("{dede:include filename='FOOTER.htm'/}", '', $html_body);

    //先屏蔽dedcms的模板代码
    $html_body = preg_replace('/\{dede:.*?\/\}/i', '', $html_body);
    $html_body = preg_replace('/\{dede:[\s\S]*?\{\/dede:[a-z]+\}/i', '', $html_body);
//    echo $html_body;
//    echo BR . BR;
    $html_segments_array[] = get_multilayer_html($html_body);
}

var_dump($html_segments_array);

//======================================================================================================================
// 项目所用函数
//======================================================================================================================
/**
 * 得到某个目录的文件列表
 * @param string $path_pattern
 * @return array|false
 */
function get_file_list($path_pattern=''){
    if(empty($path_pattern)){
        $path_pattern = INPUT_DIR . '*.*';
    }
    return glob($path_pattern);
}

/**
 * 得到文件内容
 * @param $file_path
 * @return false|string
 */
function get_file_content($file_path){
    return file_get_contents($file_path);
}

/**
 * 输出文件内容
 * @param $file_path
 * @param $html_body
 * @return bool|int
 */
function put_file_content($file_path, $html_body, $mode = FILE_APPEND){
    return file_put_contents(str_replace(INPUT_DIR, OUTPUT_DIR, $file_path), $html_body, $mode);
}

/**
 * 获得中位数 长度
 * @param $count
 * @return mixed
 */
function get_median($count){
//    return intval($count / 2);
    return $count;
}

/**
 * 返回替换HTML的正则表达式
 * @param $html_tags
 * @return mixed|string
 */
function get_html_pattern($html_tags){
    $html_pattern = str_replace('>', '(.*?)>([^<]*?)', $html_tags);
    //替换 javascript 脚本 和 css 样式内容
    $html_pattern = str_replace('<script(.*?)>([^<]*?)</script(.*?)>', '<script(.*?)>([\s\S]*?)</script(.*?)>', $html_pattern);
    $html_pattern = str_replace('<style(.*?)>([^<]*?)</style(.*?)>', '<style(.*?)>([\s\S]*?)</style(.*?)>', $html_pattern);

    // <body 后边的 和 </body>标签前边的 <script> <style> <link 直接包含
    $html_pattern = str_replace('<body(.*?)>([^<]*?)', '<body(.*?)>([\s\S]*?)', $html_pattern);
    $html_pattern = str_replace('([^<]*?)</body(.*?)>', '([\s\S]*?)</body(.*?)>', $html_pattern);

    // 转义 / 字符
    $html_pattern = str_replace('/', '\/', $html_pattern);

    $html_pattern = '/' . substr($html_pattern, 0, strrpos($html_pattern, '>') + 1) . '/i';

    return $html_pattern;
}

/**
 * 根据限定字符获取偏移量的字符串
 * @param $str
 * @param $offset
 * @param $delimiter
 * @return string
 */
function get_offset_str($str, $offset, $delimiter){
    $result_str = '';

    if(empty($str)){
        return '';
    }

    if(empty($offset)){
        return $str;
    }

    $temp = explode($delimiter, $str);
    $count = count($temp);

    if(!empty($count)){
        if($offset > $count){
            $offset = $count;
        }

        for($i=0; $i<$offset; $i++){
            $result_str .= $temp[$i] . $delimiter;
        }
    }

    return $result_str;
}


/**
 * 根据限定字符获取从尾部开始偏移量的字符串
 * @param $str
 * @param $offset
 * @param $delimiter
 * @return string
 */
function get_end_offset_str($str, $offset, $delimiter){
    $result_str = '';

    if(empty($str)){
        return '';
    }

    if(empty($offset)){
        return $str;
    }

    $temp = explode($delimiter, $str);
//    var_dump($temp);
    $count = count($temp);

    if(!empty($count)){
        if($offset > $count){
            $offset = $count;
        }

        for($i=1; $i<$offset; $i++){
            $result_str = $delimiter . $temp[$count - $i] . $result_str;
        }
    }

    return $result_str;
}

/**
 * 递归删除一个目录包含子目录和文件 (不包括自身)
 * @param $path
 */
function deldir($path){
    //如果是目录则继续
    if(is_dir($path)){
        //扫描一个文件夹内的所有文件夹和文件并返回数组
        $p = scandir($path);
        foreach($p as $val){
            //排除目录中的.和..
            if($val !="." && $val !=".."){
                //如果是目录则递归子目录，继续操作
                if(is_dir($path.$val)){
                    //子目录中操作删除文件夹和文件
                    deldir($path.$val.'/');
                    //目录清空后删除空文件夹
                    @rmdir($path.$val.'/');
                }else{
                    //如果是文件直接删除
                    unlink($path.$val);
                }
            }
        }
    }
}

/**
 * 分析 HTML 标签列表
 * @param $source_code
 * @return string|string[]|null
 */
function analysis_html($source_code){
    $html = $source_code;
    // 格式化源代码
    $html = str_replace(array("\r", "\n", "\t", "&nbsp;"), '', $html);  //去掉换行
//    $html = preg_replace('/<script[\s|>][\s\S]*?<\/script>/i', '', $html); //去掉js
    $html = preg_replace('/<script[\s|>][\s\S]*?<\/script>/i', '<script></script>', $html); //js 替换为一个 占位标签
//    $html = preg_replace('/<style[\s|>][\s\S]*?<\/style>/i', '', $html); //去掉css
    $html = preg_replace('/<style[\s|>][\s\S]*?<\/style>/i', '<style></style>', $html); //css 也替换为一个占位符
/*    $html = preg_replace('/<link [\s|>][\s\S]*?>/i', '', $html); //去掉css 链接*/
    $html = preg_replace('/<link [\s|>][\s\S]*?>/i', '<link>', $html); //css 链接 也替换为一个占位符

    $html = preg_replace('/<!--[\s\S]*?-->/', '', $html); //去掉HTML注释
//    $html = preg_replace('/<!--[\s\S]*?-->/', '<!--#-->', $html); //HTML注释 替换为一个占位符
    $html = preg_replace('/ {2,}/', ' ', $html); //多个空格替换为一个
    $html = str_replace("> <", '><', $html);  //去掉两个标签中间的空格
    $html = trim($html); // 去掉两边的空白

//    echo $html;
//    echo "\r\n\r\n\r\n";

    $pattern_html_tags = '/<[a-zA-Z1-6]+[\s|>]{1}/i'; //匹配所有HTML标签 (用\s包括回车) todo 注意javascript 里边也有HTML 标签
    $matches_html_tags = array();
    preg_match_all($pattern_html_tags, $html, $matches_html_tags);

//    var_dump($matches_html_tags);

    $htmlTags = array();
    if(isset($matches_html_tags[0][0])) {
        foreach ($matches_html_tags[0] as $item) {
            $htmlTag = str_replace(array('<', '>', ' '), '', $item);
            $htmlTags[] = $htmlTag;
        }
    }

    $uniqueHtmlTags = array_unique($htmlTags);

    if(isset($uniqueHtmlTags[0])){
        foreach($uniqueHtmlTags as $item){
            // todo xslooi 此处有bug li 会替换 link 、 b 会替换 body 和 br
            $html = preg_replace('/<' . $item . '(?!a|b|c|d|e|f|p|s|u|i|l|m|n|o|r|\/).*?>/i', '<' . $item . '>', $html);
//            echo $item;
//            echo $html;
//            echo "\r\n\r\n\r\n";
//            exit;
        }
    }
//exit;
//    echo $html;
//    exit;
//    $pattern_replace = '/>([\sa-zA-z0-9]*[\x{4e00}-\x{9fa5}\P{L}]+[\sa-zA-z0-9]*)</u'; //替换中文内容的正则
//    $html = preg_replace($pattern_replace, '><button class="fixed" data-clipboard-text="${1}" type="button"> ${1} </button><', $html);

    // 去掉标签内部内容
    $pattern_replace = '/>.*?</'; //替换标签内的所有内容为空
    $html = preg_replace($pattern_replace, '><', $html);

    $result = $html;

    return $result;
}


/**
 * 获取HTML body 中 第一个多层（>1）同级的HTML片段
 * @param $html_body
 * @return array
 */
function get_multilayer_html($html_body){
    $html_temp_body = $html_body;
    $html_segments = array();
    //匹配到HTML中body里边的 第一级 标签 注意 不匹配 <div> 直接闭合的开始标签 只匹配有空格的
    $matches_head_tags = array();
    preg_match_all('/\n<[a-z1-6]+ /i', $html_body, $matches_head_tags);

    // 得到一级的闭合标签
    if(isset($matches_head_tags[0][0])){
        foreach($matches_head_tags[0] as $key=>$value){

            $html_segment = get_closing_tag_html($value, $html_temp_body);
            $html_segment_length = strlen($html_segment);
            $html_segment_offset = strpos($html_temp_body, $html_segment);

            $html_temp_body = substr($html_temp_body, $html_segment_offset + $html_segment_length);

            $html_segments[] = $html_segment;
        }
    }

    return $html_segments;
}


/**
 * 根据 HTML 开始标签 返回该标签的整段闭合HTML代码
 * TODO 注意此函数未处理 注释中的代码 <!-- --> 脚本代码 样式代码
 * !可能有多字节字符问题
 * 不匹配 </div > 闭合标签中有空格问题
 * @param $tag_start
 * @param $html
 * @return bool|string
 */
function get_closing_tag_html($tag_start, $html){
    if(empty($tag_start) || empty($html)){
        exit(__LINE__ . __FUNCTION__ . ' Parameters Error!');
    }

    //HTML 单闭合标签
    $html_single_tag = array('br', 'hr', 'img', 'input', 'param', 'meta', 'link');

    $html_fragment = ''; //HTML闭合标签整段代码

    //直接付给body 可能用于 body 内部代码段
    $html_body = $html;

    if(false !== stripos($html, '<body')){
        $html_body = substr($html, stripos($html, '<body'));
    }

    if(false !== stripos($html_body, '</body>')){
        $html_body = substr($html_body, 0, stripos($html_body, '</body>') + 7);
    }

    //如果没有找到开始代码段
    if(stripos($html_body, $tag_start) !== false){
        $tag_name_temp = explode(' ', $tag_start);
        $tag_name = substr($tag_name_temp[0], 1);
        $tag_name = str_replace(array('<', '>'), '', $tag_name);


        $html_start = substr($html_body, strpos($html_body, $tag_start));
        if(in_array($tag_name, $html_single_tag)){
            $html_fragment = substr($html_start, 0, strpos($html_start, '>') + 1);
        }
        else{

            $html_tag_end = '</' . $tag_name . '>';
            $html_tag_end_count = substr_count($html_body, $html_tag_end);

            $html_fragment = substr($html_start, 0, strpos($html_start, $html_tag_end) + strlen($html_tag_end));
            $html_fragment_length = strlen($html_fragment);
            $html_tag_start_count = substr_count($html_fragment, '<' . $tag_name . ' ') + substr_count($html_fragment, '<' . $tag_name . '>');
            $end_count = 1; //标签结束标志

            //遍历HTML 闭合标签代码 找到闭合位置
            for($i=1; $i<$html_tag_end_count; $i++){

                if($html_tag_start_count > $end_count){

                    $html_fragment = substr($html_start, $html_fragment_length);
                    $html_fragment = substr($html_fragment, 0, strpos($html_fragment, $html_tag_end) + strlen($html_tag_end));
                    $html_fragment = substr($html_start, 0, $html_fragment_length + strlen($html_fragment));
                    $html_fragment_length = strlen($html_fragment);
                    $html_tag_start_count = substr_count($html_fragment, '<' . $tag_name . ' ') + substr_count($html_fragment, '<' . $tag_name . '>');
                    $end_count++;
                }
                else{
                    break;
                }
            }
        }

    }

    return $html_fragment;
}
