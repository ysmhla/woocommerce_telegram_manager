<?php
//created by Yash Mahala, please keep credit here


// === CONFIGURATION ===
$telegramToken = ""; //generate using botfather
$woocommerceUrl = "https://mysite.com";
$consumerKey = ""; // generate in Woocommerce>Settings
$consumerSecret = "";
$defaultUsername = ""; // eg MY 
$defaultPassword = ""; // eg 12345

// === TELEGRAM UPDATE ===
$update = json_decode(file_get_contents("php://input"), true);
file_put_contents("log.txt", json_encode($update, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

if (!$update) {
    exit;
}

// === HANDLE MESSAGE ===
$message = $update["message"] ?? $update["callback_query"] ?? null;
$chatId = $message["chat"]["id"] ?? $message["message"]["chat"]["id"] ?? null;
$userId = $message["from"]["id"] ?? null;
$text = trim($message["text"] ?? $message["data"] ?? "");

if (!$chatId) {
    exit;
}

// === STATE MANAGEMENT ===
$stateFile = "state_$chatId.json";
function loadState($chatId) {
    global $stateFile;
    if (file_exists($stateFile)) {
        return json_decode(file_get_contents($stateFile), true);
    }
    return [
        'logged_in' => false,
        'state' => null,
        'product_data' => [],
        'awaiting_password' => false,
        'username' => null
    ];
}

function saveState($chatId, $state) {
    global $stateFile;
    file_put_contents($stateFile, json_encode($state));
    file_put_contents("debug_log.txt", "State saved for $chatId: " . json_encode($state) . "\n", FILE_APPEND);
}

$userState = loadState($chatId);

// === AUTHENTICATION ===
if (!$userState['logged_in']) {
    if (!$userState['awaiting_password']) {
        sendMessage($chatId, "👤 Please enter your username:");
        $userState['awaiting_password'] = true;
        saveState($chatId, $userState);
    } else {
        if (!$userState['username']) {
            $userState['username'] = $text;
            sendMessage($chatId, "🔒 Please enter your password:");
            saveState($chatId, $userState);
        } else {
            if ($text === $defaultPassword && $userState['username'] === $defaultUsername) {
                $userState['logged_in'] = true;
                $userState['awaiting_password'] = false;
                $userState['username'] = null;
                sendMessage($chatId, "✅ Successfully logged in!");
                showMainMenu($chatId);
                saveState($chatId, $userState);
            } else {
                sendMessage($chatId, "❌ Invalid credentials. Please enter your username again:");
                $userState['username'] = null;
                $userState['awaiting_password'] = true;
                saveState($chatId, $userState);
            }
        }
    }
    exit;
}

// === HANDLE COMMANDS ===
if ($text === "/start" || $text === "Back to Menu") {
    showMainMenu($chatId);
    $userState['state'] = null;
    $userState['product_data'] = [];
    saveState($chatId, $userState);
}

if ($userState['state']) {
    handleState($chatId, $text, $update, $userState);
} else {
    switch ($text) {
        case "Add Product":
            sendMessage($chatId, "Enter product name:");
            $userState['state'] = "add_product_name";
            saveState($chatId, $userState);
            break;
        case "Update Product":
            sendMessage($chatId, "Enter product name to search (or part of it):");
            $userState['state'] = "update_product_search";
            saveState($chatId, $userState);
            break;
        case "Delete Product":
            sendMessage($chatId, "Enter product name to search (or part of it):");
            $userState['state'] = "delete_product_search";
            saveState($chatId, $userState);
            break;
        case "Add Category":
            $categories = getWooCategories();
            $categoryTree = buildCategoryTree($categories);
            sendMessage($chatId, "Enter a category from this list:\n\n" . $categoryTree . "\n\nType the exact category name:");
            $userState['state'] = "add_category";
            saveState($chatId, $userState);
            break;
        case "Make Out of Stock":
            sendMessage($chatId, "Enter product name to search (or part of it):");
            $userState['state'] = "out_of_stock_search";
            saveState($chatId, $userState);
            break;
    }
}

// === FUNCTIONS ===
function showMainMenu($chatId) {
    $keyboard = [
        'inline_keyboard' => [
            [['text' => 'Add Product', 'callback_data' => 'Add Product']],
            [['text' => 'Update Product', 'callback_data' => 'Update Product']],
            [['text' => 'Delete Product', 'callback_data' => 'Delete Product']],
            [['text' => 'Add Category', 'callback_data' => 'Add Category']],
            [['text' => 'Make Out of Stock', 'callback_data' => 'Make Out of Stock']]
        ]
    ];
    sendMessage($chatId, "📋 Main Menu:", $keyboard);
}

function handleState($chatId, $text, $update, &$userState) {
    switch ($userState['state']) {
        case "add_product_name":
            $userState['product_data']['name'] = $text;
            sendMessage($chatId, "Enter product price:");
            $userState['state'] = "add_product_price";
            saveState($chatId, $userState);
            break;
            
        case "add_product_price":
            $userState['product_data']['price'] = $text;
            sendMessage($chatId, "Enter product description:");
            $userState['state'] = "add_product_desc";
            saveState($chatId, $userState);
            break;
            
        case "add_product_desc":
            $userState['product_data']['desc'] = $text;
            $categories = getWooCategories();
            $categoryTree = buildCategoryTree($categories);
            $keyboard = buildCategoryKeyboard($categories);
            $keyboard['inline_keyboard'][] = [['text' => 'Skip', 'callback_data' => 'skip']];
            sendMessage($chatId, "Select a category:\n\n" . $categoryTree, $keyboard);
            $userState['state'] = "add_product_category";
            saveState($chatId, $userState);
            break;
            
        case "add_product_category":
            if ($text === "skip") {
                $skipKeyboard = [['text' => 'Skip', 'callback_data' => 'skip']];
                sendMessage($chatId, "Enter tags separated by commas:", ['inline_keyboard' => [$skipKeyboard]]);
                $userState['state'] = "add_product_tags";
            } else {
                $categories = getWooCategories();
                $categoryId = array_search($text, array_column($categories, 'name'));
                if ($categoryId !== false) {
                    $userState['product_data']['category'] = $categories[$categoryId]['id'];
                    $skipKeyboard = [['text' => 'Skip', 'callback_data' => 'skip']];
                    sendMessage($chatId, "Enter tags separated by commas:", ['inline_keyboard' => [$skipKeyboard]]);
                    $userState['state'] = "add_product_tags";
                } else {
                    sendMessage($chatId, "❌ Invalid category. Try again:");
                }
            }
            saveState($chatId, $userState);
            break;
            
        case "add_product_tags":
            if ($text === "skip") {
                $skipKeyboard = [['text' => 'Skip', 'callback_data' => 'skip']];
                sendMessage($chatId, "Please upload product image:", ['inline_keyboard' => [$skipKeyboard]]);
                $userState['state'] = "add_product_image";
            } else {
                $tags = array_map('trim', explode(',', $text));
                $userState['product_data']['tags'] = $tags;
                $skipKeyboard = [['text' => 'Skip', 'callback_data' => 'skip']];
                sendMessage($chatId, "Please upload product image:", ['inline_keyboard' => [$skipKeyboard]]);
                $userState['state'] = "add_product_image";
            }
            saveState($chatId, $userState);
            break;
            
        case "add_product_image":
            if ($text === "skip") {
                addProduct($chatId, $userState);
            } elseif (isset($update['message']['photo'])) {
                $photo = end($update['message']['photo']);
                $userState['product_data']['image'] = $photo['file_id'];
                file_put_contents("image_log.txt", "Photo ID stored: " . $photo['file_id'] . "\n", FILE_APPEND);
                addProduct($chatId, $userState);
            } else {
                $skipKeyboard = [['text' => 'Skip', 'callback_data' => 'skip']];
                sendMessage($chatId, "Please upload an image:", ['inline_keyboard' => [$skipKeyboard]]);
            }
            break;
            
        case "update_product_search":
            $userState['product_data']['search_term'] = $text;
            $userState['product_data']['page'] = 1;
            showProductSearchResults($chatId, $userState, 'update');
            $userState['state'] = "update_product_select";
            saveState($chatId, $userState);
            break;
            
        case "update_product_select":
            if (strpos($text, "page_") === 0) {
                $userState['product_data']['page'] = (int)substr($text, 5);
                showProductSearchResults($chatId, $userState, 'update');
            } elseif (is_numeric($text)) {
                $userState['product_data']['id'] = $text;
                $skipKeyboard = [['text' => 'Skip', 'callback_data' => 'skip']];
                sendMessage($chatId, "Enter new product name (or skip):", ['inline_keyboard' => [$skipKeyboard]]);
                $userState['state'] = "update_product_name";
            }
            saveState($chatId, $userState);
            break;
            
        case "update_product_name":
            if ($text !== "skip") {
                $userState['product_data']['name'] = $text;
            }
            $skipKeyboard = [['text' => 'Skip', 'callback_data' => 'skip']];
            sendMessage($chatId, "Enter new price (or skip):", ['inline_keyboard' => [$skipKeyboard]]);
            $userState['state'] = "update_product_price";
            saveState($chatId, $userState);
            break;
            
        case "update_product_price":
            if ($text !== "skip") {
                $userState['product_data']['price'] = $text;
            }
            $skipKeyboard = [['text' => 'Skip', 'callback_data' => 'skip']];
            sendMessage($chatId, "Enter new description (or skip):", ['inline_keyboard' => [$skipKeyboard]]);
            $userState['state'] = "update_product_desc";
            saveState($chatId, $userState);
            break;
            
        case "update_product_desc":
            if ($text !== "skip") {
                $userState['product_data']['desc'] = $text;
            }
            $categories = getWooCategories();
            $categoryTree = buildCategoryTree($categories);
            $keyboard = buildCategoryKeyboard($categories);
            $keyboard['inline_keyboard'][] = [['text' => 'Skip', 'callback_data' => 'skip']];
            sendMessage($chatId, "Select a new category (or skip):\n\n" . $categoryTree, $keyboard);
            $userState['state'] = "update_product_category";
            saveState($chatId, $userState);
            break;
            
        case "update_product_category":
            if ($text !== "skip") {
                $categories = getWooCategories();
                $categoryId = array_search($text, array_column($categories, 'name'));
                if ($categoryId !== false) {
                    $userState['product_data']['category'] = $categories[$categoryId]['id'];
                }
            }
            $skipKeyboard = [['text' => 'Skip', 'callback_data' => 'skip']];
            sendMessage($chatId, "Enter new tags separated by commas (or skip):", ['inline_keyboard' => [$skipKeyboard]]);
            $userState['state'] = "update_product_tags";
            saveState($chatId, $userState);
            break;
            
        case "update_product_tags":
            if ($text !== "skip") {
                $tags = array_map('trim', explode(',', $text));
                $userState['product_data']['tags'] = $tags;
            }
            $skipKeyboard = [['text' => 'Skip', 'callback_data' => 'skip']];
            sendMessage($chatId, "Please upload new image (or skip):", ['inline_keyboard' => [$skipKeyboard]]);
            $userState['state'] = "update_product_image";
            saveState($chatId, $userState);
            break;
            
        case "update_product_image":
            if ($text === "skip") {
                updateProduct($chatId, $userState);
            } elseif (isset($update['message']['photo'])) {
                $photo = end($update['message']['photo']);
                $userState['product_data']['image'] = $photo['file_id'];
                file_put_contents("image_log.txt", "Photo ID stored for update: " . $photo['file_id'] . "\n", FILE_APPEND);
                updateProduct($chatId, $userState);
            } else {
                $skipKeyboard = [['text' => 'Skip', 'callback_data' => 'skip']];
                sendMessage($chatId, "Please upload an image:", ['inline_keyboard' => [$skipKeyboard]]);
            }
            break;
            
        case "delete_product_search":
            $userState['product_data']['search_term'] = $text;
            $userState['product_data']['page'] = 1;
            showProductSearchResults($chatId, $userState, 'delete');
            $userState['state'] = "delete_product_select";
            saveState($chatId, $userState);
            break;
            
        case "delete_product_select":
            if (strpos($text, "page_") === 0) {
                $userState['product_data']['page'] = (int)substr($text, 5);
                showProductSearchResults($chatId, $userState, 'delete');
            } elseif (is_numeric($text)) {
                $response = wooRequest("products/$text", "DELETE", ["force" => true]);
                sendMessage($chatId, $response ? "✅ Product deleted successfully!" : "❌ Error deleting product:", ['inline_keyboard' => [[['text' => 'Back to Menu', 'callback_data' => 'Back to Menu']]]]);
                $userState['state'] = null;
                $userState['product_data'] = [];
            }
            saveState($chatId, $userState);
            break;
            
        case "out_of_stock_search":
            $userState['product_data']['search_term'] = $text;
            $userState['product_data']['page'] = 1;
            showProductSearchResults($chatId, $userState, 'out_of_stock');
            $userState['state'] = "out_of_stock_select";
            saveState($chatId, $userState);
            break;
            
        case "out_of_stock_select":
            if (strpos($text, "page_") === 0) {
                $userState['product_data']['page'] = (int)substr($text, 5);
                showProductSearchResults($chatId, $userState, 'out_of_stock');
            } elseif (is_numeric($text)) {
                $response = wooRequest("products/$text", "PUT", ["stock_status" => "outofstock"]);
                sendMessage($chatId, $response ? "✅ Product marked as out of stock!" : "❌ Error updating stock status:", ['inline_keyboard' => [[['text' => 'Back to Menu', 'callback_data' => 'Back to Menu']]]]);
                $userState['state'] = null;
                $userState['product_data'] = [];
            }
            saveState($chatId, $userState);
            break;
            
        case "add_category":
            $response = wooRequest("products/categories", "POST", ["name" => $text]);
            if ($response && isset($response['id'])) {
                sendMessage($chatId, "✅ Category '$text' added successfully! ID: " . $response['id'], ['inline_keyboard' => [[['text' => 'Back to Menu', 'callback_data' => 'Back to Menu']]]]);
            } else {
                $error = $response['message'] ?? 'Unknown error';
                sendMessage($chatId, "❌ Error adding category: $error", ['inline_keyboard' => [[['text' => 'Back to Menu', 'callback_data' => 'Back to Menu']]]]);
            }
            $userState['state'] = null;
            $userState['product_data'] = [];
            saveState($chatId, $userState);
            break;
    }
}

function addProduct($chatId, &$userState) {
    global $telegramToken;
    $product = [
        "name" => $userState['product_data']['name'],
        "regular_price" => $userState['product_data']['price'],
        "description" => $userState['product_data']['desc'],
        "type" => "simple",
        "status" => "publish",
        "sku" => "SKU-" . rand(1000, 9999)
    ];
    
    if (isset($userState['product_data']['category'])) {
        $product['categories'] = [['id' => $userState['product_data']['category']]];
    }
    
    if (isset($userState['product_data']['tags'])) {
        $product['tags'] = array_map(function($tag) { return ['name' => $tag]; }, $userState['product_data']['tags']);
    }
    
    if (isset($userState['product_data']['image'])) {
        $fileUrl = getTelegramFileUrl($userState['product_data']['image'], $telegramToken);
        if ($fileUrl) {
            $product['images'] = [['src' => $fileUrl]];
            file_put_contents("image_log.txt", "Image URL for add: $fileUrl\n", FILE_APPEND);
        } else {
            file_put_contents("image_log.txt", "Failed to get image URL for file_id: " . $userState['product_data']['image'] . "\n", FILE_APPEND);
        }
    }
    
    $response = wooRequest("products", "POST", $product);
    $keyboard = [['text' => 'Back to Menu', 'callback_data' => 'Back to Menu']];
    if ($response && isset($response['id'])) {
        sendMessage($chatId, "✅ Product added successfully! Product ID: " . $response['id'], ['inline_keyboard' => [$keyboard]]);
    } else {
        $error = $response['message'] ?? 'Unknown error';
        sendMessage($chatId, "❌ Error adding product: $error", ['inline_keyboard' => [$keyboard]]);
        file_put_contents("image_log.txt", "WooCommerce error: $error\n", FILE_APPEND);
    }
    $userState['state'] = null;
    $userState['product_data'] = [];
    saveState($chatId, $userState);
}

function updateProduct($chatId, &$userState) {
    global $telegramToken;
    $product = [];
    
    if (isset($userState['product_data']['name'])) {
        $product['name'] = $userState['product_data']['name'];
    }
    if (isset($userState['product_data']['price'])) {
        $product['regular_price'] = $userState['product_data']['price'];
    }
    if (isset($userState['product_data']['desc'])) {
        $product['description'] = $userState['product_data']['desc'];
    }
    if (isset($userState['product_data']['category'])) {
        $product['categories'] = [['id' => $userState['product_data']['category']]];
    }
    if (isset($userState['product_data']['tags'])) {
        $product['tags'] = array_map(function($tag) { return ['name' => $tag]; }, $userState['product_data']['tags']);
    }
    if (isset($userState['product_data']['image'])) {
        $fileUrl = getTelegramFileUrl($userState['product_data']['image'], $telegramToken);
        if ($fileUrl) {
            $product['images'] = [['src' => $fileUrl]];
            file_put_contents("image_log.txt", "Image URL for update: $fileUrl\n", FILE_APPEND);
        } else {
            file_put_contents("image_log.txt", "Failed to get image URL for file_id: " . $userState['product_data']['image'] . "\n", FILE_APPEND);
        }
    }
    
    $response = wooRequest("products/" . $userState['product_data']['id'], "PUT", $product);
    $keyboard = [['text' => 'Back to Menu', 'callback_data' => 'Back to Menu']];
    if ($response && isset($response['id'])) {
        sendMessage($chatId, "✅ Product updated successfully!", ['inline_keyboard' => [$keyboard]]);
    } else {
        $error = $response['message'] ?? 'Unknown error';
        sendMessage($chatId, "❌ Error updating product: $error", ['inline_keyboard' => [$keyboard]]);
        file_put_contents("image_log.txt", "WooCommerce error: $error\n", FILE_APPEND);
    }
    $userState['state'] = null;
    $userState['product_data'] = [];
    saveState($chatId, $userState);
}

function showProductSearchResults($chatId, &$userState, $action) {
    $searchTerm = $userState['product_data']['search_term'];
    $page = $userState['product_data']['page'];
    $perPage = 10;
    
    $products = wooRequest("products?search=" . urlencode($searchTerm) . "&per_page=$perPage&page=$page", "GET");
    if (!$products) {
        sendMessage($chatId, "❌ No products found.", ['inline_keyboard' => [[['text' => 'Back to Menu', 'callback_data' => 'Back to Menu']]]]);
        return;
    }
    
    $total = count($products);
    $message = "Found products (Page $page):\n\n";
    $keyboard = ['inline_keyboard' => []];
    
    foreach ($products as $index => $product) {
        $message .= ($index + 1 + ($page - 1) * $perPage) . ". " . $product['name'] . " (ID: " . $product['id'] . ")\n";
        $keyboard['inline_keyboard'][] = [['text' => $product['name'] . " (ID: " . $product['id'] . ")", 'callback_data' => $product['id']]];
    }
    
    $pagination = [];
    if ($page > 1) {
        $pagination[] = ['text' => 'Previous', 'callback_data' => 'page_' . ($page - 1)];
    }
    if ($total === $perPage) {
        $pagination[] = ['text' => 'Next', 'callback_data' => 'page_' . ($page + 1)];
    }
    if (!empty($pagination)) {
        $keyboard['inline_keyboard'][] = $pagination;
    }
    $keyboard['inline_keyboard'][] = [['text' => 'Back to Menu', 'callback_data' => 'Back to Menu']];
    
    sendMessage($chatId, $message, $keyboard);
}

function getWooCategories() {
    $categories = [];
    $page = 1;
    do {
        $response = wooRequest("products/categories?per_page=100&page=$page", "GET");
        if ($response) {
            $categories = array_merge($categories, $response);
            $page++;
        } else {
            break;
        }
    } while (count($response) === 100);
    return $categories;
}

function buildCategoryTree($categories, $parentId = 0, $level = 0) {
    $tree = "";
    foreach ($categories as $category) {
        if ($category['parent'] == $parentId) {
            $tree .= str_repeat("  ", $level) . "- " . $category['name'] . "\n";
            $tree .= buildCategoryTree($categories, $category['id'], $level + 1);
        }
    }
    return $tree;
}

function buildCategoryKeyboard($categories) {
    $keyboard = ['inline_keyboard' => []];
    $row = [];
    foreach ($categories as $category) {
        $row[] = ['text' => $category['name'], 'callback_data' => $category['name']];
        if (count($row) == 2) {
            $keyboard['inline_keyboard'][] = $row;
            $row = [];
        }
    }
    if (!empty($row)) {
        $keyboard['inline_keyboard'][] = $row;
    }
    return $keyboard;
}

function getTelegramFileUrl($fileId, $token) {
    $response = json_decode(file_get_contents("https://api.telegram.org/bot$token/getFile?file_id=$fileId"), true);
    file_put_contents("image_log.txt", "Telegram getFile response: " . json_encode($response) . "\n", FILE_APPEND);
    
    if ($response['ok'] && isset($response['result']['file_path'])) {
        $filePath = $response['result']['file_path'];
        return "https://api.telegram.org/file/bot$token/$filePath";
    }
    return false;
}

function sendMessage($chatId, $text, $replyMarkup = []) {
    global $telegramToken;
    $url = "https://api.telegram.org/bot$telegramToken/sendMessage";
    $data = [
        "chat_id" => $chatId,
        "text" => $text,
        "parse_mode" => "HTML"
    ];
    if (!empty($replyMarkup)) {
        $data['reply_markup'] = json_encode($replyMarkup);
    }
    $result = file_get_contents($url . "?" . http_build_query($data));
    file_put_contents("image_log.txt", "Send message result: $result\n", FILE_APPEND);
}

function wooRequest($endpoint, $method, $data = []) {
    global $woocommerceUrl, $consumerKey, $consumerSecret;
    $url = "$woocommerceUrl/wp-json/wc/v3/$endpoint";
    $auth = base64_encode("$consumerKey:$consumerSecret");

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Basic $auth",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    file_put_contents("woo_log.txt", "Request: $method $url\nData: " . json_encode($data) . "\nResponse (HTTP $httpCode): " . $response . "\n\n", FILE_APPEND);
    return json_decode($response, true);
}
?>