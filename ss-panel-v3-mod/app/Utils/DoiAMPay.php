<?php
namespace App\Utils;
/**
 * Made By Leo
 * 黛米付接口
 * 2.1.4版本
 * 适用于 V1 API  黛米付面板V1
 * copyright all rights reserved
 */

use App\Services\View;
use App\Services\Auth;
use App\Models\Node;
use App\Models\TrafficLog;
use App\Models\InviteCode;
use App\Models\CheckInLog;
use App\Models\Ann;
use App\Models\Speedtest;
use App\Models\Shop;
use App\Models\Coupon;
use App\Models\Bought;
use App\Models\Ticket;
use App\Services\Config;
use App\Utils\Hash;
use App\Utils\Tools;
use App\Utils\Radius;
use App\Utils\Wecenter;
use App\Models\RadiusBan;
use App\Models\DetectLog;
use App\Models\DetectRule;
use voku\helper\AntiXSS;
use App\Models\User;
use App\Models\Code;
use App\Models\Ip;
use App\Models\Paylist;
use App\Models\LoginIp;
use App\Models\BlockIp;
use App\Models\UnblockIp;
use App\Models\Payback;
use App\Models\Relay;
use App\Utils\QQWry;
use App\Utils\GA;
use App\Utils\Geetest;
use App\Utils\Telegram;
use App\Utils\TelegramSessionManager;
use App\Utils\Pay;
use App\Utils\URL;
use App\Services\Mail;

class DoiAMPay{

    protected const VERSION = "2.1.4";

    protected $enabled = [
        'wepay'=>1, // 1 启用 0 关闭
        'alipay'=>1, // 1 启用 0 关闭
        'qqpay'=>1, // 1 启用 0 关闭
        ];

    protected $data = [
        'wepay'=>[
            'mchid' => 1511606484,   // 商户号
            'phone' => 17088884666, //手机号
            'token' => "IWDxq5wILmuWZEQKj1hEFFsHXBAotsD8" // 安全验证码
        ],
        'alipay'=>[
            'mchid' => 1511606511,   // 商户号
            'phone' => 17088884666, //手机号
            'token' => "bDtPDQwi76RQO3ISnDryrk9Hbe3SsAkQ" // 安全验证码
        ],
        'qqpay'=>[
            'mchid' => 1511606539,   // 商户号
            'phone' => 17088884666, //手机号
            'token' => "yKgdXms8n4HS8DGW5YmItOxSwzsw3lmz" // 安全验证码
        ],
    ];

    public function smarty()
    {
        $this->smarty = View::getSmarty();
        return $this->smarty;
    }

    public function view()
    {
        return $this->smarty();
    }

    public function route_home($request, $response, $args){
        $pageNum = 1;
        if (isset($request->getQueryParams()["page"])) {
            $pageNum = $request->getQueryParams()["page"];
        }
        $codes = Code::where('type', '<>', '-2')->where('userid', '=', Auth::getUser()->id)->orderBy('id', 'desc')->paginate(15, ['*'], 'page', $pageNum);
        $codes->setPath('/user/code');
        return $this->view()->assign('codes', $codes)->assign('enabled',$this->enabled)->display('user/doiam.tpl');
    }
    public function handel($request, $response, $args){
        $type = $request->getParam('type');
        $price = $request->getParam('price');
        if($this->enabled[$type]==0){
            return json_encode(['errcode'=>-1,'errmsg'=>"非法的支付方式."]);
        }
        if($price <= 0){
            return json_encode(['errcode'=>-1,'errmsg'=>"非法的金额."]);
        }
        $user = Auth::getUser();
        $settings = $this->data[$type];
        $pl = new Paylist();
        $pl->userid = $user->id;
        $pl->total = $price;
        $pl->save();
        $data = [
            'trade' => $pl->id,
            'price' => $price,
            'phone' => $settings['phone'],
            'mchid' => $settings['mchid'],
            'subject' => Config::get("appName")."充值".$price."元",
            'body' => Config::get("appName")."充值".$price."元",
        ];
        $data = DoiAM::sign($data,$settings['token']);
        $ret = DoiAM::post("https://api.daimiyun.cn/v2/".$type."/create",$data);
        $result = json_decode($ret,true);
        if($result and $result['errcode']==0){
            $result['pid']=$pl->id;
            return json_Encode($result);
        }else{
            return json_encode([
                'errcode'=>-1,
                'errmsg' => "接口调用失败!".$ret,
            ]);
        }
        return $result;
    }
    public function status($request, $response, $args){
        return json_encode(Paylist::find($_POST['pid']));
    }
    public function handel_return($request, $response, $args){
        $money = $_GET['money'];
         echo "您已经成功支付 $money 元,正在跳转..";
         echo <<<HTML
<script>
    location.href="/user/doiam";
</script>
HTML;
        return;
    }
    public function handel_callback($request, $response, $args){
        $order_data = $_POST;
        $status    = $order_data['status'];         //获取传递过来的交易状态
        $invoiceid = $order_data['out_trade_no'];     //订单号
        $transid   = $order_data['trade_no'];       //转账交易号
        $amount    = $order_data['money'];          //获取递过来的总价格
        if(!DoiAM::checksign($_POST,$this->data[$args['type']]['token'])){
            return (json_encode(array('errcode'=>2333)));
        }
        if ($status == 'success') {
            $p=Paylist::find($invoiceid);
            if($p->status==1){
                return json_encode(['errcode'=>0]);
            }
            $p->status=1;
            $p->save();
            $user = User::find($p->userid);
            $user->money += $p->total;
            $user->save();
            return json_encode(['errcode'=>0]);
        }else{
            return '';
        }
    }
}
class DoiAM{
    public static function sort(&$array){
        ksort($array);
    }
    public static function getsign($array,$key){
        unset($array['sign']);
        self::sort($array);
        $sss=http_build_query($array);
        $sign=hash("sha256",$sss.$key);
        $sign=sha1($sign.hash("sha256",$key));
        return $sign;
    }
    public static function sign($array,$key){
        $array['sign']=self::getSign($array,$key);
        return $array;
    }
    public static function checksign($array,$key){
        $new = $array;
        $new=self::sign($new,$key);
        if(!isset($array['sign'])){
            return false;
        }
        return $array['sign']==$new['sign'];
    }
    public static function post($url, $data = null){
    	$curl = curl_init();
    	curl_setopt($curl, CURLOPT_URL, $url);
    	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
    	curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
    	if (!empty($data)){
    	curl_setopt($curl, CURLOPT_POST, 1);
    	curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    	}
    	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    	$output = curl_exec($curl);
    	curl_close($curl);
    	return $output;
    }
}
