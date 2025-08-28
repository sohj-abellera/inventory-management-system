<?php

// Global database connection
global $conn;
set_time_limit(0);

$startTime = microtime(true); // Store the start time in seconds since Unix epoch

ob_start();
echo "Current real-time: " . date('Y-m-d H:i:s');
ob_flush();
flush();

echo "Initializing script";
for ($i = 5; $i > 0; $i--) {
    echo "Starting in $i...";
    ob_flush();
    flush();
    sleep(1); // Pause for 1 second
}

// Function to log the last date in the database
function logCurrentDateToDB($currentDate)
{
    global $conn;

    $formattedDate = date('Y-m-d', $currentDate);
    $query = $conn->prepare("REPLACE INTO progress_log (id, last_logged_date) VALUES (1, ?)");
    $query->bind_param('s', $formattedDate);

    if (!$query->execute()) {
        die("Failed to log progress: " . $query->error);
    }

    echo "Progress saved for the last date: $formattedDate\n";
    ob_flush();
    flush();
}

// Function to get the last logged date from the database
function getSavedDateFromDB()
{
    global $conn;

    $query = "SELECT last_logged_date FROM progress_log WHERE id = 1";
    $result = $conn->query($query);

    if ($result && $row = $result->fetch_assoc()) {
        return strtotime($row['last_logged_date']);
    }

    return false;
}

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Helper function to generate a list of times between two hours
function generateTimeSlots($startHour, $endHour)
{
    $timeSlots = [];
    for ($hour = $startHour; $hour < $endHour; $hour++) {
        for ($minute = 0; $minute < 60; $minute += 1) { // 1-minute interval
            $timeSlots[] = sprintf('%02d:%02d:00', $hour, $minute);
        }
    }
    return $timeSlots;
}

// Helper function to generate random time between given hours
function generateRandomTime(&$timeSlots)
{
    if (empty($timeSlots)) {
        throw new Exception("No available timeslots for today.");
    }

    // Define time ranges with weights
    $timeRanges = [
        '7-10' => 80,  // 80% chance
        '10-14' => 10, // 10% chance
        '14-21' => 10  // 10% chance
    ];

    // Randomly choose a time range based on the weights
    $randomPercent = rand(1, 100);
    $selectedRange = '';
    $cumulative = 0;

    foreach ($timeRanges as $range => $weight) {
        $cumulative += $weight;
        if ($randomPercent <= $cumulative) {
            $selectedRange = $range;
            break;
        }
    }

    // Filter time slots based on the selected range
    [$startHour, $endHour] = explode('-', $selectedRange);
    $filteredSlots = array_filter($timeSlots, function ($slot) use ($startHour, $endHour) {
        $hour = (int)explode(':', $slot)[0];
        return $hour >= $startHour && $hour < $endHour;
    });

    if (empty($filteredSlots)) {
        throw new Exception("No available timeslots in the selected range.");
    }

    // Randomly select a time from the filtered slots
    $randomIndex = array_rand($filteredSlots);
    $randomTime = $filteredSlots[$randomIndex];
    unset($timeSlots[array_search($randomTime, $timeSlots)]); // Remove selected time

    return $randomTime;
}

// Function to generate a random birthdate between two years
function generateRandomDate($startYear, $endYear)
{
    $timestamp = rand(strtotime("$startYear-01-01"), strtotime("$endYear-12-31"));
    return date('Y-m-d', $timestamp);
}

function createCustomer($createdOn)
{
    global $conn;

    $firstName = "sample_first_name_" . rand(1000, 9999);
    $lastName = "sample_last_name_" . rand(1000, 9999);
    $email = "sample_email_" . rand(1000, 9999) . "@sample.com";
    $phoneNumber = "09" . rand(100000000, 999999999);
    $address = "sample_address_" . rand(1000, 9999);
    $birthDate = generateRandomDate(1995, 2004);
    $gender = rand(0, 1) ? 'Male' : 'Female';

    $query = $conn->prepare("INSERT INTO customers (first_name, last_name, email, phone_number, address, birth_date, gender, created_on) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $query->bind_param('ssssssss', $firstName, $lastName, $email, $phoneNumber, $address, $birthDate, $gender, $createdOn);

    if ($query->execute()) {
        return $conn->insert_id;
    } else {
        die("Customer creation failed: " . $query->error);
    }
}

function createSalesOrder($customerId, $totalAmount, $createdOn, &$totalUnitsSold, &$totalRevenue)
{
    global $conn;

    $query = "SELECT employee_id FROM users WHERE user_role = 'sales_manager' ORDER BY RAND() LIMIT 1";
    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $managedBy = $row['employee_id'];
    } else {
        die("No sales manager found in the database. Please check the users table.");
    }

    $paymentMethods = ['cash', 'credit', 'debit', 'online'];
    $paymentMethod = $paymentMethods[array_rand($paymentMethods)];

    $query = $conn->prepare("INSERT INTO sales_orders (customer_id, total_amount, managed_by, payment_method, created_on) 
              VALUES (?, ?, ?, ?, ?)");
    $query->bind_param('idiss', $customerId, $totalAmount, $managedBy, $paymentMethod, $createdOn);

    if (!$query->execute()) {
        die("Sales order creation failed: " . $query->error);
    }

    $salesOrderId = $conn->insert_id;

    $randPercent = rand(1, 100);
    $itemCount = ($randPercent <= 70) ? rand(1, 3) : rand(5, 10);

    

    for ($i = 0; $i < $itemCount; $i++) {
        $query = "SELECT product_id, price, quantity, reorder_point FROM products ORDER BY RAND() LIMIT 1";
        $result = $conn->query($query);
        if (!$result || $result->num_rows === 0) {
            die("No products found in the database. Please check the products table.");
        }
        $product = $result->fetch_assoc();

        $productId = $product['product_id'];
        $price = $product['price'];
        $currentStock = $product['quantity'];
        $reorderPoint = $product['reorder_point'];

        $randPercent = rand(1, 100);
        $quantity = ($randPercent <= 70) ? rand(5, 10) : rand(10, 20);

        if ($quantity > $currentStock) {
            $quantity = $currentStock;
        }

        $totalPrice = $price * $quantity;
        $totalAmount += $totalPrice;

        $query = $conn->prepare("INSERT INTO order_items (sales_order_id, product_id, quantity, total_price, created_on) 
                  VALUES (?, ?, ?, ?, ?)");
        $query->bind_param('iiids', $salesOrderId, $productId, $quantity, $totalPrice, $createdOn);

        if (!$query->execute()) {
            die("Order item creation failed: " . $query->error);
        }

        $query = $conn->prepare("UPDATE products SET quantity = quantity - ? WHERE product_id = ?");
        $query->bind_param('ii', $quantity, $productId);

        if (!$query->execute()) {
            die("Failed to update product stock: " . $query->error);
        }

        // Check if the product's stock has reached the reorder point
        $currentStock -= $quantity;
        if ($currentStock <= $reorderPoint) {
            createReorderRequest($productId, $createdOn);
        }

        // Ensure the product ID key exists in the tracking arrays before incrementing
        if (!isset($totalUnitsSold[$productId])) {
            $totalUnitsSold[$productId] = 0;
        }
        if (!isset($totalRevenue[$productId])) {
            $totalRevenue[$productId] = 0.0;
        }

        $totalUnitsSold[$productId] += $quantity;
        $totalRevenue[$productId] += $totalPrice;
    }

    $query = $conn->prepare("UPDATE sales_orders SET total_amount = ? WHERE sales_order_id = ?");
    $query->bind_param('di', $totalAmount, $salesOrderId);

    if (!$query->execute()) {
        die("Failed to update sales order total: " . $query->error);
    }

    // Log the sale to user_activities
    $detail = "Made a sale";
    $activityType = "sales";

    // Check if an SCO was triggered for this sale
    $triggeredSCO = rand(1, 100) <= 10;
    if ($triggeredSCO) {
        $handledByQuery = "SELECT employee_id FROM users WHERE user_role = 'supply_chain_manager' ORDER BY RAND() LIMIT 1";
        $handledByResult = $conn->query($handledByQuery);
        $handledBy = $handledByResult->fetch_assoc()['employee_id'] ?? null;

        if ($handledBy) {
            $acceptedOn = date('Y-m-d H:i:s', strtotime($createdOn) + rand(20, 120));
            $routes = ['Route 1', 'Route 2', 'Route 3', 'Route 4', 'Route 5', 'Route 6'];
            $details = (rand(1, 100) <= 90) ? $routes[array_rand(['Route 1', 'Route 2'])] : $routes[array_rand($routes)];

            createSupplyChainOrder('sales_order', $salesOrderId, $handledBy, $acceptedOn, $details);
            $detail = "Made a sale and requested a delivery";
        }
    }

    logUserActivity($managedBy, $activityType, $detail, $salesOrderId, $createdOn);

    return $salesOrderId;
}


function createSupplyChainOrder($source, $relatedId, $handledBy, $acceptedOn, $details)
{
    global $conn;

    $status = 'on_process'; // Set default status to "on_process"

    $query = $conn->prepare("INSERT INTO supply_chain_orders (source, related_id, handled_by, accepted_on, details, status) 
              VALUES (?, ?, ?, ?, ?, ?)");
    $query->bind_param('siisss', $source, $relatedId, $handledBy, $acceptedOn, $details, $status);

    if (!$query->execute()) {
        die("Supply chain order creation failed: " . $query->error);
    }

    $scOrderId = $conn->insert_id; // Get the ID of the newly created SCO

    // Log the initial "on_process" status
    $activityType = 'supply_chain';
    $logDetails = ($source === 'sales_order') ? 'Accepted SCO-SD' : 'Accepted SCO-RR';
    logUserActivity($handledBy, $activityType, $logDetails, $scOrderId, $acceptedOn);
}

function updateSupplyChainOrdersStatus()
{
    global $conn;

    // Fetch all SCOs in "on_process" or "in_transit" states
    $query = "SELECT * FROM supply_chain_orders WHERE status IN ('on_process', 'in_transit')";
    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $id = $row['sc_order_id']; // Supply chain order ID
            $source = $row['source']; // Source of the SCO
            $acceptedOn = strtotime($row['accepted_on']);
            $status = $row['status'];
            $handledBy = $row['handled_by']; // Employee handling the SCO

            $nextStatus = null;
            $nextTransitionTime = null;
            $logDetails = '';

            // Determine the next status and time based on the source
            if ($status === 'on_process') {
                $nextStatus = 'in_transit';
                $nextTransitionTime = $acceptedOn + rand(10 * 60, 25 * 60); // Add 10-25 minutes
                $logDetails = ($source === 'sales_order') 
                    ? 'SCO-SD out for delivery' 
                    : 'SCO-RR out for delivery';
            } elseif ($status === 'in_transit') {
                $nextStatus = 'completed';
                $nextTransitionTime = ($source === 'sales_order') 
                    ? $acceptedOn + rand(60 * 60, 180 * 60) // Add 1-3 hours
                    : $acceptedOn + rand(86400, 432000);   // Add 1-5 days
                $logDetails = ($source === 'sales_order') 
                    ? 'SCO-SD delivered' 
                    : 'SCO-RR delivered';
            }

            // Update the status and transition time
            if ($nextStatus) {
                $transitionTime = date('Y-m-d H:i:s', $nextTransitionTime);
                $query = $conn->prepare("UPDATE supply_chain_orders 
                                          SET status = ?, delivered_on = ? 
                                          WHERE sc_order_id = ?");
                $query->bind_param('ssi', $nextStatus, $transitionTime, $id);

                if (!$query->execute()) {
                    die("Failed to update supply chain order status: " . $query->error);
                }

                // Log the status transition in user_activities
                $activityType = 'supply_chain';
                logUserActivity($handledBy, $activityType, $logDetails, $id, $transitionTime);

                // If completed, handle the reorder request
                if ($nextStatus === 'completed' && $source === 'inventory_reorder') {
                    handleReorderCompletion($row['related_id'], $transitionTime);
                }
            }
        }
    }
}

function handleReorderCompletion($requestId, $deliveredOn)
{
    global $conn;

    // Update reorder_requests table
    $query = $conn->prepare("UPDATE reorder_requests SET completed_on = ? WHERE request_id = ?");
    $query->bind_param('si', $deliveredOn, $requestId);

    if (!$query->execute()) {
        die("Failed to update reorder request: " . $query->error);
    }

    // Fetch quantity and product_id from reorder_requests
    $query = $conn->prepare("SELECT product_id, quantity FROM reorder_requests WHERE request_id = ?");
    $query->bind_param('i', $requestId);
    $query->execute();
    $result = $query->get_result();

    if ($row = $result->fetch_assoc()) {
        $productId = $row['product_id'];
        $quantity = $row['quantity'];

        // Update products table
        $query = $conn->prepare("UPDATE products SET quantity = quantity + ? WHERE product_id = ?");
        $query->bind_param('ii', $quantity, $productId);

        if (!$query->execute()) {
            die("Failed to update product stock: " . $query->error);
        }
    }
}

function createReorderRequest($productId, $requestedOn)
{
    global $conn;

    $query = "SELECT supplier_id FROM products WHERE product_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $stmt->bind_result($supplierId);
    $stmt->fetch();
    $stmt->close();

    if (!$supplierId) {
        die("Supplier ID not found for product ID: $productId");
    }

    // Get a random employee for `requested_by`
    $roleChance = rand(1, 100);
    $userRole = $roleChance <= 5 ? 'admin' : 'inventory_manager';
    $query = "SELECT employee_id FROM users WHERE user_role = ? ORDER BY RAND() LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('s', $userRole);
    $stmt->execute();
    $stmt->bind_result($requestedBy);
    $stmt->fetch();
    $stmt->close();

    if (!$requestedBy) {
        die("No user found with role: $userRole");
    }

    // Determine priority and reason
    $priorities = ['low', 'medium', 'high'];
    $priority = $priorities[array_rand($priorities)];
    $reorderReason = match ($priority) {
        'low' => 'Reached its reorder point',
        'medium' => 'For preparation on the following events',
        'high' => 'Stocks depleting very fast',
    };

    // Weighted ranges for quantity: 40-45, 45-50, 55-60 (each 33% chance)
    $randPercent = rand(1, 100);

    if ($randPercent <= 33) {
        $quantity = rand(60, 65);
    } elseif ($randPercent <= 66) {
        $quantity = rand(65, 70);
    } else {
        $quantity = rand(70, 75);
    }

    $query = $conn->prepare("INSERT INTO reorder_requests (product_id, quantity, requested_by, date_of_request, supplier_id, priority, reorder_reason) 
              VALUES (?, ?, ?, ?, ?, ?, ?)");
    $query->bind_param('iiissss', $productId, $quantity, $requestedBy, $requestedOn, $supplierId, $priority, $reorderReason);

    if (!$query->execute()) {
        die("Failed to create reorder request: " . $query->error);
    }

    // Trigger a supply chain order for the new reorder request
    $requestId = $conn->insert_id; // Get the ID of the newly inserted reorder request

    $handledByQuery = "SELECT employee_id FROM users WHERE user_role = 'supply_chain_manager' ORDER BY RAND() LIMIT 1";
    $handledByResult = $conn->query($handledByQuery);
    $handledBy = $handledByResult->fetch_assoc()['employee_id'] ?? null;

    if (!$handledBy) {
        die("No supply chain manager found. Check the users table.");
    }

    $acceptedOn = date('Y-m-d H:i:s', strtotime($requestedOn) + rand(20, 240));
    $routes = ['Route 1', 'Route 2', 'Route 3', 'Route 4', 'Route 5', 'Route 6'];
    $details = (rand(1, 100) <= 90) ? $routes[array_rand(['Route 1', 'Route 2'])] : $routes[array_rand($routes)];

    createSupplyChainOrder('inventory_reorder', $requestId, $handledBy, $acceptedOn, $details);
}

function logUserActivity($performedBy, $activityType, $details, $referenceId, $dateOfActivity)
{
    global $conn;

    $query = $conn->prepare("INSERT INTO user_activities (performed_by, activity_type, details, reference_id, date_of_activity) 
              VALUES (?, ?, ?, ?, ?)");
    $query->bind_param('sssis', $performedBy, $activityType, $details, $referenceId, $dateOfActivity);

    if (!$query->execute()) {
        die("Failed to log user activity: " . $query->error);
    }
}
//----------------------------------------------
function logInventoryMovementsForSales()
{
    global $conn;

    // Fetch rows from order_items that haven't been logged in inventory_movements
    $query = "SELECT oi.product_id, oi.quantity, so.created_on AS date_of_movement, oi.sales_order_id AS reference_id 
              FROM order_items oi 
              JOIN sales_orders so ON oi.sales_order_id = so.sales_order_id 
              LEFT JOIN inventory_movements im ON im.reference_id = oi.sales_order_id AND im.movement_type = 'sale'
              WHERE im.movement_id IS NULL";

    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        $insertQuery = $conn->prepare("INSERT INTO inventory_movements (movement_type, product_id, quantity, date_of_movement, reference_id) 
                                       VALUES ('sale', ?, ?, ?, ?)");
        while ($row = $result->fetch_assoc()) {
            $insertQuery->bind_param(
                'iisi',
                $row['product_id'],
                $row['quantity'],
                $row['date_of_movement'],
                $row['reference_id']
            );

            if (!$insertQuery->execute()) {
                die("Failed to log inventory movement for sale: " . $insertQuery->error);
            }
        }
    }
}

function logInventoryMovementsForRestocks()
{
    global $conn;

    // Fetch rows from reorder_requests that haven't been logged in inventory_movements
    $query = "SELECT rr.product_id, rr.quantity, rr.completed_on AS date_of_movement, rr.request_id AS reference_id 
              FROM reorder_requests rr
              LEFT JOIN inventory_movements im ON im.reference_id = rr.request_id AND im.movement_type = 'restock'
              WHERE rr.completed_on IS NOT NULL AND im.movement_id IS NULL";

    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        $insertQuery = $conn->prepare("INSERT INTO inventory_movements (movement_type, product_id, quantity, date_of_movement, reference_id) 
                                       VALUES ('restock', ?, ?, ?, ?)");
        while ($row = $result->fetch_assoc()) {
            $insertQuery->bind_param(
                'iisi',
                $row['product_id'],
                $row['quantity'],
                $row['date_of_movement'],
                $row['reference_id']
            );

            if (!$insertQuery->execute()) {
                die("Failed to log inventory movement for restock: " . $insertQuery->error);
            }
        }
    }
}

// Main script
$currentDate = getSavedDateFromDB() ?: strtotime('2024-01-01'); // Resume from saved date or start from Jan 1, 2020
$endDate = strtotime('2025-01-16');


$totalUnitsSold = [];
$totalRevenue = [];

// Create product_history table if it doesn't exist
$query = "
CREATE TABLE IF NOT EXISTS products_history AS 
SELECT *, NULL AS snapshot_date FROM products WHERE 1=0;
";
$conn->query($query);

// Add snapshot_date column if it doesn't exist
$query = "ALTER TABLE products_history ADD COLUMN IF NOT EXISTS snapshot_date DATETIME";
$conn->query($query);


while ($currentDate <= $endDate) {
    $month = date('F', $currentDate);
    $year = date('Y', $currentDate);
    $monthDays = date('t', $currentDate); // Number of days in the current month

    $timeSlots = generateTimeSlots(7, 21); // Available times for the day
    $month = date('F', $currentDate); // Get the current month as a string

// Adjust daily sales based on the month
if (in_array($month, ['April', 'May', 'June', 'July', 'August'])) {
    // Special months: 50% chance for 2-3 or 3-4 sales
    $randPercent = rand(1, 100);
    $daySales = ($randPercent <= 50) ? rand(4, 8) : rand(8, 12);
} else {
    // Normal days: 80% chance for 0-2 sales, 20% chance for 2-3 sales
    $randPercent = rand(1, 100);
    $daySales = ($randPercent <= 80) ? rand(0, 2) : rand(3, 10);
}


    for ($i = 0; $i < $daySales; $i++) {
        try {
            $time = generateRandomTime($timeSlots);
        } catch (Exception $e) {
            echo "No more available times for " . date('Y-m-d', $currentDate) . ". Skipping remaining sales.\n";
            break;
        }

        $createdOn = date('Y-m-d', $currentDate) . " $time";

        $timeSlots = array_filter($timeSlots, fn($slot) => $slot > $time);

        $useExistingCustomer = rand(1, 100) <= 40;

        if ($useExistingCustomer) {
            $query = "SELECT customer_id FROM customers ORDER BY RAND() LIMIT 1";
            $result = $conn->query($query);

            $customerId = ($result && $result->num_rows > 0) ? $result->fetch_assoc()['customer_id'] : createCustomer($createdOn);
        } else {
            $customerId = createCustomer($createdOn);
        }

        $salesOrderId = createSalesOrder($customerId, 0, $createdOn, $totalUnitsSold, $totalRevenue);
    }

    if (date('j', $currentDate) == $monthDays) {
        logCurrentDateToDB($currentDate);

        // Update products table with total_units_sold and total_revenue
        foreach ($totalUnitsSold as $productId => $unitsSold) {
            $query = $conn->prepare("UPDATE products SET total_units_sold = total_units_sold + ? WHERE product_id = ?");
            $query->bind_param('ii', $unitsSold, $productId);
            $query->execute();
        }

        foreach ($totalRevenue as $productId => $revenue) {
            $query = $conn->prepare("UPDATE products SET total_revenue = total_revenue + ? WHERE product_id = ?");
            $query->bind_param('di', $revenue, $productId);
            $query->execute();
        }

        // Snapshot the products table into product_history
        $snapshotDate = date('Y-m-d', $currentDate);
        $query = "INSERT INTO products_history SELECT *, ? AS snapshot_date FROM products";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('s', $snapshotDate);
        $stmt->execute();

        // Reset tracking arrays for the next month
        $totalUnitsSold = [];
        $totalRevenue = [];
    }

    // Update statuses for supply chain orders
    updateSupplyChainOrdersStatus();

    // Log inventory movements for sales and restocks
    logInventoryMovementsForSales();
    logInventoryMovementsForRestocks();

    $currentDate = strtotime('+1 day', $currentDate);
}

$executionTime = microtime(true) - $startTime;
echo "Execution Time: " . gmdate("H:i:s", $executionTime) . "\n";
$conn->close();