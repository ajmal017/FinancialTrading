<?php

namespace Tests\Unit\Services;

use Tests\TestCase;

use \App\Services\AwsService;

class AwsServiceTest extends TestCase
{
//    public function testGetInstance()
//    {
//        $awsService = new AwsService();
//
//        $instance = $awsService->getCurrentInstance();
//        dd($instance);
//    }

    public function testGetIpAddressWithTag()
    {
        $awsService = new AwsService();

        $instance = $awsService->getCurrentInstanceIp();
        dd($instance);
    }

    public function testCreateSpotRequest()
    {
        $awsService = new AwsService();

        $params = [
            'server_count' => 1,
            'interruption_behavior'=>'terminate',
            'image_id' => 'ami-0baa2426cb508d778',
            'instance_type'=> 't1.micro',
            'tags'=> [
                'Key' => 'test_key',
                'Value' => 'yay',
            ]


        ];

        $awsService->requestSpotFleet($params);
    }
}
