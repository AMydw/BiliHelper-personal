<?php

/**
 *  Website: https://mudew.com/
 *  Author: Lkeme
 *  License: The MIT License
 *  Email: Useri@live.cn
 *  Updated: 2020 ~ 2021
 */

namespace BiliHelper\Plugin;

use BiliHelper\Core\Log;
use BiliHelper\Core\Curl;
use BiliHelper\Util\TimeLock;


class PkRaffle extends BaseRaffle
{
    const ACTIVE_TITLE = '大乱斗';
    const ACTIVE_SWITCH = 'USE_PK';

    use TimeLock;

    protected static $wait_list = [];
    protected static $finish_list = [];
    protected static $all_list = [];


    /**
     * @use 解析数据
     * @param int $room_id
     * @param array $data
     * @return bool
     */
    protected static function parseLotteryInfo(int $room_id, array $data): bool
    {
        // 防止异常
        if (!array_key_exists('pk', $data['data'])) {
            return false;
        }
        $de_raw = $data['data']['pk'];
        if (empty($de_raw)) {
            return false;
        }

        foreach ($de_raw as $pk) {
            // 无效抽奖
            if ($pk['status'] != 1) {
                continue;
            }
            // 去重
            if (self::toRepeatLid($pk['id'])) {
                continue;
            }
            // 推入列表
            $data = [
                'room_id' => $room_id,
                'raffle_id' => $pk['id'],
                'raffle_name' => '大乱斗',
                'wait' => time() + mt_rand(5, 25)
            ];
            Statistics::addPushList(self::ACTIVE_TITLE);
            array_push(self::$wait_list, $data);
        }
        return true;
    }


    /**
     * @use 创建抽奖任务
     * @param array $raffles
     * @return array
     */
    protected static function createLottery(array $raffles): array
    {
        $url = 'https://api.live.bilibili.com/xlive/lottery-interface/v1/pk/join';
        $tasks = [];
        $results = [];
        $user_info = User::parseCookies();
        foreach ($raffles as $raffle) {
            $payload = [
                'id' => $raffle['raffle_id'],
                'roomid' => $raffle['room_id'],
                'csrf_token' => $user_info['token'],
                "csrf" => $user_info['token'],
            ];
            array_push($tasks, [
                'payload' => Sign::common($payload),
                'source' => [
                    'room_id' => $raffle['room_id'],
                    'raffle_id' => $raffle['raffle_id']
                ]
            ]);
        }
        $results = Curl::async('app', $url, $tasks);
        # print_r($results);
        return $results;
    }

    /**
     * @use 解析抽奖信息
     * @param array $results
     * @return mixed|void
     */
    protected static function parseLottery(array $results)
    {
        foreach ($results as $result) {
            $data = $result['source'];
            $content = $result['content'];
            $de_raw = json_decode($content, true);
            /*
             * {'code': 0, 'message': '0', 'ttl': 1, 'data': {'id': 343560, 'gift_type': 0, 'award_id': '1', 'award_text': '辣条X1', 'award_image': 'https://i0.hdslb.com/bfs/live/da6656add2b14a93ed9eb55de55d0fd19f0fc7f6.png', 'award_num': 0, 'title': '大乱斗获胜抽奖'}}
             * {'code': -1, 'message': '抽奖已结束', 'ttl': 1}
             * {'code': -2, 'message': '您已参加过抽奖', 'ttl': 1}
             * {"code":-403,"data":null,"message":"访问被拒绝","msg":"访问被拒绝"}
             */
            if (isset($de_raw['code']) && $de_raw['code'] == 0) {
                Log::notice("房间 {$data['room_id']} 编号 {$data['raffle_id']} " . self::ACTIVE_TITLE . ": {$de_raw['data']['award_text']}");
                Statistics::addSuccessList(self::ACTIVE_TITLE);
            } elseif (isset($de_raw['msg']) && $de_raw['code'] == -403 && $de_raw['msg'] == '访问被拒绝') {
                Log::debug("房间 {$data['room_id']} 编号 {$data['raffle_id']} " . self::ACTIVE_TITLE . ": {$de_raw['message']}");
                self::pauseLock();
            } else {
                Log::notice("房间 {$data['room_id']} 编号 {$data['raffle_id']} " . self::ACTIVE_TITLE . ": {$de_raw['message']}");
            }
        }
    }
}
