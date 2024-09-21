<?php
// Copyright (C) 2019 Remy van Elst

// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Affero General Public License for more details.

// You should have received a copy of the GNU Affero General Public License
// along with this program.  If not, see <https://www.gnu.org/licenses/>.

function add_domain_check($id, $visitor_ip) {
    global $current_domain;
    global $current_link;
    global $pre_check_file;
    global $check_file;
    global $title;

    // Always initialize the result array with an empty errors array
    $result = array('errors' => [], 'success' => []);

    // Fetch the pre-check JSON data
    $pre_check_json_file = file_get_contents($pre_check_file);
    if ($pre_check_json_file === FALSE) {
        $result['errors'][] = "Can't open database.";
        return $result;
    }
    $pre_check_json_a = json_decode($pre_check_json_file, true);
    if ($pre_check_json_a === null && json_last_error() !== JSON_ERROR_NONE) {
        $result['errors'][] = "Can't read database: " . htmlspecialchars(json_last_error_msg());
        return $result;
    }

    // Check if ID exists in the pre-check database
    if (!isset($pre_check_json_a[$id]) || !is_array($pre_check_json_a[$id])) {
        $result['errors'][] = "Can't find record in database for: " . htmlspecialchars($id);
        return $result;
    }

    // Fetch the main check database
    $file = file_get_contents($check_file);
    if ($file === FALSE) {
        $result['errors'][] = "Can't open database.";
        return $result;
    }
    $json_a = json_decode($file, true);
    if ($json_a === null && json_last_error() !== JSON_ERROR_NONE) {
        $result['errors'][] = "Can't read database: " . htmlspecialchars(json_last_error_msg());
        return $result;
    }

    // Check for existing domain/email combination in the main database
    foreach ($json_a as $key => $value) {
        if ($key == $id || ($value["domain"] == $pre_check_json_a[$id]['domain'] && $value["email"] == $pre_check_json_a[$id]['email'])) {
            $result['errors'][] = "Domain/email combo for " . htmlspecialchars($pre_check_json_a[$id]['domain']) . " already exists.";
            return $result;
        }
    }

    // Validate the domain
    $domains = validate_domains($pre_check_json_a[$id]['domain']);
    if (!empty($domains['errors'])) {
        // Merge validation errors into result['errors']
        $result['errors'] = array_merge($result['errors'], $domains['errors']);
        return $result;
    }

    // Update the main check database with the new domain check
    $json_a[$id] = array(
        "domain" => $pre_check_json_a[$id]['domain'],
        "email" => $pre_check_json_a[$id]['email'],
        "errors" => 0,
        "visitor_pre_register_ip" => $pre_check_json_a[$id]['visitor_pre_register_ip'],
        "pre_add_date" => $pre_check_json_a[$id]['pre_add_date'],
        "visitor_confirm_ip" => $visitor_ip,
        "confirm_date" => time()
    );
    $json = json_encode($json_a);
    if (file_put_contents($check_file, $json, LOCK_EX) === FALSE) {
        $result['errors'][] = "Can't write database.";
        return $result;
    }

    // Remove the pre-check entry
    unset($pre_check_json_a[$id]);
    $pre_check_json = json_encode($pre_check_json_a);
    if (file_put_contents($pre_check_file, $pre_check_json, LOCK_EX) === FALSE) {
        $result['errors'][] = "Can't write database.";
        return $result;
    }

    // Send confirmation email
    $unsublink = "https://" . $current_link . "/unsubscribe.php?id=" . $id;
    $to = $json_a[$id]['email'];
    $subject = $title . " subscription confirmed for " . htmlspecialchars($json_a[$id]['domain']) . ".";
    $message = "Hello,\n\nSomeone, hopefully you, has confirmed the subscription of their website to the " . $title . ".\n\nDomain: " . trim(htmlspecialchars($json_a[$id]['domain'])) . "\nEmail: " . trim(htmlspecialchars($json_a[$id]['email'])) . "\nIP subscription confirmed from: " . htmlspecialchars($visitor_ip) . "\nDate subscribed confirmed: " . date("Y-m-d H:i:s T") . "\n\nTo unsubscribe, visit: " . $unsublink;
    $message = wordwrap($message, 70, "\r\n");
    $headers = 'From: noreply@' . $current_domain . "\r\n" .
               'Reply-To: noreply@' . $current_domain . "\r\n" .
               'X-Visitor-IP: ' . $visitor_ip;

    if (mail($to, $subject, $message, $headers) === FALSE) {
        $result['errors'][] = "Can't send email.";
        return $result;
    }

    $result['success'][] = true;
    return $result;
}

