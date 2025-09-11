<?php
$url = "https://fcm.googleapis.com/fcm/send";


$server_key = "BBD435Y3Qib-8dPJ_-eEs2ScDyXZ2WhWzFzS9lmuKv_xQ4LSPcDnZZVqS7FHBtinlM_tNNQYsocQMXCptrchO68";

$message = array(
    "data" => array(
        "title" => "New Order ",
        "body" => "You have a new order. Please check the admin panel for details.",
        "icon" => "../images/CC.png",
        "image" => "../images/kape.png",
        "click_action" => "https://cupscuddles.com/admin/admin.php"
    ),

    "registration_ids" =>[
         ""
    ]
       

);


  $options =array(
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: key=' . $server_key
        ),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => json_encode($message)
    );

    $curl = curl_init();
    curl_setopt_array($curl, $options);
    $response = curl_exec($curl);

    if ($response === false) {
        $error = curl_error($curl);
        error_log("cURL Error: " . $error);
    } else {
        error_log("FCM Response: " . $response);
    }



    ?>