<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .menu-scroll {
            overflow-y: auto;
            min-height: 0;
            flex-grow: 1;
        }
    </style>
</head>

<body>
    <?php
    session_start();
    $menuFile = 'menu.txt';
    $menuItems = [];
    $searchQuery = isset($_GET['search']) ? strtolower(trim($_GET['search'])) : '';
    $sortBy = $_GET['sort_by'] ?? '';
    $order = $_GET['order'] ?? 'asc';
    $categoryFilter = $_GET['category'] ?? '';

    // Inisialisasi keranjang jika belum ada
    if (!isset($_SESSION['qty']) || !is_array($_SESSION['qty'])) {
        $_SESSION['qty'] = [];
    }

    // Proses checkout (simulasi)
    $showModal = false;
    $checkoutErrorMessage = ''; // New variable for checkout error messages
    if (isset($_POST['checkout'])) {
        if (empty($_SESSION['qty'])) {
            $showModal = true;
        } else {
            $_SESSION['qty'] = []; // Reset keranjang setelah transaksi
        }
    }

    if (file_exists($menuFile)) {
        $lines = file($menuFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $index => $line) {
            $parts = explode('|', $line);
            if (count($parts) === 5) {
                $name = trim($parts[0]);
                $image = trim($parts[1]);
                $price = (int) trim($parts[2]);
                $stock = (int) trim($parts[3]);
                $category = trim($parts[4]);

                if ($searchQuery && strpos(strtolower($name), $searchQuery) === false) {
                    continue;
                }

                if ($categoryFilter !== '' && $categoryFilter !== 'all' && $category !== $categoryFilter) {
                    continue;
                }

                $menuItems[] = [
                    'name' => $name,
                    'image' => $image,
                    'price' => $price,
                    'stock' => $stock,
                    'category' => $category,
                    'index' => $index
                ];
            }
        }

        if ($sortBy === 'name') {
            usort($menuItems, function ($a, $b) use ($order) {
                return $order === 'asc' ? strcmp($a['name'], $b['name']) : strcmp($b['name'], $a['name']);
            });
        } elseif ($sortBy === 'price') {
            usort($menuItems, function ($a, $b) use ($order) {
                return $order === 'asc' ? $a['price'] - $b['price'] : $b['price'] - $a['price'];
            });
        }
    }

    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['add_to_cart'])) {
            $name = $_POST['item_name'];
            $price = (int) $_POST['item_price'];
            // Find the item in menuItems to get its current stock
            $currentItemStock = 0;
            foreach ($menuItems as $menuItem) {
                if ($menuItem['name'] === $name) {
                    $currentItemStock = $menuItem['stock'];
                    break;
                }
            }

            if (isset($_SESSION['cart'][$name])) {
                // Check if adding one more exceeds stock
                if ($_SESSION['cart'][$name]['qty'] + 1 > $currentItemStock) {
                    $checkoutErrorMessage = "Cannot add more " . htmlspecialchars($name) . ". Stock limit reached.";
                } else {
                    $_SESSION['cart'][$name]['qty'] += 1;
                }
            } else {
                // Check if adding the first item exceeds stock (shouldn't happen if stock > 0)
                if (1 > $currentItemStock) {
                    $checkoutErrorMessage = "Cannot add " . htmlspecialchars($name) . ". Out of stock.";
                } else {
                    $_SESSION['cart'][$name] = ['price' => $price, 'qty' => 1];
                }
            }
        } elseif (isset($_POST['update_cart'])) {
            foreach ($_POST['qty'] as $name => $qty) {
                $qty = (int)$qty;
                // Find the item in menuItems to get its current stock
                $currentItemStock = 0;
                foreach ($menuItems as $menuItem) {
                    if ($menuItem['name'] === $name) {
                        $currentItemStock = $menuItem['stock'];
                        break;
                    }
                }

                if ($qty <= 0) {
                    unset($_SESSION['cart'][$name]);
                } elseif ($qty > $currentItemStock) {
                    $checkoutErrorMessage = "Cannot update quantity for " . htmlspecialchars($name) . ". Only " . $currentItemStock . " in stock.";
                    $_SESSION['cart'][$name]['qty'] = $currentItemStock; // Set to max available stock
                } else {
                    $_SESSION['cart'][$name]['qty'] = $qty;
                }
            }
        } elseif (isset($_POST['confirm_checkout'])) {
            $originalLines = file($menuFile, FILE_IGNORE_NEW_LINES);
            $canCheckout = true;
            $stockIssues = [];

            // First, validate stock for all items in the cart
            foreach ($_SESSION['cart'] as $cartItemName => $cartItemDetails) {
                $qtyPurchased = $cartItemDetails['qty'];
                $foundInMenu = false;
                foreach ($menuItems as $menuItem) {
                    if ($menuItem['name'] === $cartItemName) {
                        $foundInMenu = true;
                        if ($qtyPurchased > $menuItem['stock']) {
                            $canCheckout = false;
                            $stockIssues[] = "Not enough stock for " . htmlspecialchars($cartItemName) . ". Available: " . $menuItem['stock'] . ", Requested: " . $qtyPurchased;
                        }
                        break;
                    }
                }
                if (!$foundInMenu) {
                    // This case should ideally not happen if cart is populated from menu
                    $canCheckout = false;
                    $stockIssues[] = "Item " . htmlspecialchars($cartItemName) . " not found in menu.";
                }
            }

            if (empty($_SESSION['cart'])) {
                $canCheckout = false;
                $checkoutErrorMessage = "Your cart is empty. Please add items before checking out.";
            } elseif (!$canCheckout) {
                $showModal = true; // Show error modal
                $checkoutErrorMessage = implode("<br>", $stockIssues);
            } else {
                // If validation passes, proceed with updating stock and creating receipt
                foreach ($menuItems as $item) {
                    $name = $item['name'];
                    $index = $item['index'];
                    if (isset($_SESSION['cart'][$name])) {
                        $qtyPurchased = $_SESSION['cart'][$name]['qty'];
                        $newStock = max(0, $item['stock'] - $qtyPurchased);
                        $originalLines[$index] = implode(' | ', [
                            $item['name'],
                            $item['image'],
                            $item['price'],
                            $newStock,
                            $item['category']
                        ]);
                    }
                }
                file_put_contents($menuFile, implode("\n", $originalLines));

                // Generate receipt
                $receiptDir = 'receipts';
                if (!file_exists($receiptDir)) {
                    mkdir($receiptDir, 0777, true);
                }

                // Generate unique filename
                $dateStr = date('d-m-Y');
                $filenameBase = 'struk_' . $dateStr;
                $fileExt = '.txt';

                // Find next available increment number
                $increment = 1;
                do {
                    $filename = $filenameBase . '_' . $increment . $fileExt;
                    $fullPath = $receiptDir . '/' . $filename;
                    $increment++;
                } while (file_exists($fullPath));

                // Create receipt content
                $receiptContent = "=== RECEIPT ===\n";
                $receiptContent .= "Date: " . date('d/m/Y H:i:s') . "\n";
                $receiptContent .= "Order Number: " . date("ymd") . "\n";
                $receiptContent .= str_repeat("-", 30) . "\n";

                $subtotal = 0;
                foreach ($_SESSION['cart'] as $name => $item) {
                    $itemTotal = $item['price'] * $item['qty'];
                    $subtotal += $itemTotal;
                    $receiptContent .= sprintf(
                        "%-20s %2d x %7s %8s\n",
                        $name,
                        $item['qty'],
                        'Rp ' . number_format($item['price'], 0, ',', '.'),
                        'Rp ' . number_format($itemTotal, 0, ',', '.')
                    );
                }

                $tax = round($subtotal * 0.1);
                $total = $subtotal + $tax;

                $receiptContent .= str_repeat("-", 30) . "\n";
                $receiptContent .= sprintf("%-20s %17s\n", "Sub Total:", 'Rp ' . number_format($subtotal, 0, ',', '.'));
                $receiptContent .= sprintf("%-20s %17s\n", "Tax:", 'Rp ' . number_format($tax, 0, ',', '.'));
                $receiptContent .= sprintf("%-20s %17s\n", "Total:", 'Rp ' . number_format($total, 0, ',', '.'));
                $receiptContent .= str_repeat("=", 30) . "\n";
                $receiptContent .= "Thank you for your purchase!\n";

                // Save receipt to file
                file_put_contents($fullPath, $receiptContent);

                $_SESSION['cart'] = [];
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            }
        }
    }
    ?>

    <div class=" container-fluid">
        <div class="row vh-100">
            <div class="col-md-2 col-lg-1 bg-light p-2 d-flex flex-column">
                <div class="d-flex flex-column align-items-center gap-3">
                    <a href="index.php" class="mt-5">
                        <img src="images/1.png" alt="Home" width="24">
                    </a>
                    <hr>
                    <a href="?category=all" class="btn btn-outline-light mb-2"><img src="images/home.png" alt="All FnB" width="24"></a>
                    <a href="?category=coffee" class="btn btn-outline-light mb-2"><img src="images/hot-beverage.png" alt="Coffee" width="24"></a>
                    <a href="?category=non-coffee" class="btn btn-outline-light mb-2"><img src="images/soda.png" alt="Non-Coffee" width="24"></a>
                    <a href="?category=food" class="btn btn-outline-light mb-2"><img src="images/food.png" alt="Food" width="24"></a>
                </div>
            </div>

            <div class="col-md-7 col-lg-8 d-flex flex-column p-0">
                <div class="p-4">
                    <form method="get" action="">
                        <div class="row g-2 align-items-center">
                            <div class="col-md-4">
                                <input type="text" name="search" class="form-control" placeholder="Search for items..." value="<?= htmlspecialchars($searchQuery) ?>">
                            </div>
                            <div class="col-md-2">
                                <select name="category" class="form-select">
                                    <option value="">All Categories</option>
                                    <option value="food" <?= $categoryFilter == 'food' ? 'selected' : '' ?>>Food</option>
                                    <option value="coffee" <?= $categoryFilter == 'coffee' ? 'selected' : '' ?>>Coffee</option>
                                    <option value="non-coffee" <?= $categoryFilter == 'non-coffee' ? 'selected' : '' ?>>Non-Coffee</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select name="sort_by" class="form-select">
                                    <option value="">Sort By</option>
                                    <option value="name" <?= $sortBy == 'name' ? 'selected' : '' ?>>Name</option>
                                    <option value="price" <?= $sortBy == 'price' ? 'selected' : '' ?>>Price</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select name="order" class="form-select">
                                    <option value="asc" <?= $order == 'asc' ? 'selected' : '' ?>>Asc</option>
                                    <option value="desc" <?= $order == 'desc' ? 'selected' : '' ?>>Desc</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button class="btn btn-outline-secondary w-100" type="submit">Go</button>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="menu-scroll flex-grow-1" style="height: 100%; max-height: 100%; overflow-y: auto;">
                    <div class="row g-3">
                        <?php foreach ($menuItems as $item): ?>
                            <div class="col-6 col-md-4 col-lg-3">
                                <div class="card text-center">
                                    <div class="card-body">
                                        <img src="images/<?= htmlspecialchars($item['image']) ?>" class="mb-2" alt="<?= htmlspecialchars($item['name']) ?>">
                                        <h6 class="card-title"><?= htmlspecialchars($item['name']) ?></h6>
                                        <p class="fw-bold">Rp <?= number_format($item['price'], 0, ',', '.') ?></p>
                                        <p class="text-muted small">Stock: <?= $item['stock'] ?></p>
                                        <?php if ($item['stock'] > 0): ?>
                                            <form method="post" action="">
                                                <input type="hidden" name="item_name" value="<?= htmlspecialchars($item['name']) ?>">
                                                <input type="hidden" name="item_price" value="<?= $item['price'] ?>">
                                                <button class="btn btn-sm btn-outline-success" type="submit" name="add_to_cart">Add</button>
                                            </form>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-secondary" disabled>Out of Stock</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Cart Section -->
            <div class="col-md-3 col-lg-3 bg-white p-4 border-start">
                <h6 class="mb-4">Order Number:
                    <span class="text-muted"><?= date("ymd") ?></span>
                </h6>
                <form method="post">
                    <div id="cart-items">
                        <?php $subtotal = 0; ?>
                        <?php foreach ($_SESSION['cart'] as $name => $item): ?>
                            <?php $itemTotal = $item['price'] * $item['qty'];
                            $subtotal += $itemTotal; ?>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <div><?= htmlspecialchars($name) ?></div>
                                    <div class="input-group input-group-sm mt-1">
                                        <input type="number" class="form-control text-center" name="qty[<?= htmlspecialchars($name) ?>]" value="<?= $item['qty'] ?>">
                                    </div>
                                </div>
                                <div class="text-end fw-bold">Rp <?= number_format($itemTotal, 0, ',', '.') ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn btn-sm btn-outline-primary w-100 mb-3" type="submit" name="update_cart">Update Cart</button>
                </form>
                <?php
                $tax = round($subtotal * 0.1);
                $total = $subtotal + $tax;
                ?>
                <ul class="list-group mb-3">
                    <li class="list-group-item d-flex justify-content-between">
                        <span>Sub Total</span>
                        <strong>Rp <?= number_format($subtotal, 0, ',', '.') ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span>Tax</span>
                        <strong>Rp <?= number_format($tax, 0, ',', '.') ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span>Total</span>
                        <strong>Rp <?= number_format($total, 0, ',', '.') ?></strong>
                    </li>
                </ul>
                <button class="btn btn-warning w-100" data-bs-toggle="modal" data-bs-target="#checkoutModal">Pay Now</button>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="modal fade" id="checkoutModal" tabindex="-1" aria-labelledby="checkoutModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="checkoutModalLabel">Confirm Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Are you sure the transaction has been completed?
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="post">
                        <button type="submit" name="confirm_checkout" class="btn btn-primary">Yes, Confirm</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if ($showModal): ?>
        <!-- Modal Bootstrap -->
        <div class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0, 0, 0, 0.5);">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title">Transaction Failed</h5>
                    </div>
                    <div class="modal-body">
                        <p><?= $checkoutErrorMessage ?></p>
                    </div>
                    <div class="modal-footer">
                        <a href="index.php" class="btn btn-secondary">Back</a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($checkoutErrorMessage) && !$showModal): // Show error message if not already showing a modal 
    ?>
        <div class="modal fade show d-block" tabindex="-1" style="background-color: rgba(0, 0, 0, 0.5);">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title">Warning</h5>
                    </div>
                    <div class="modal-body">
                        <p><?= $checkoutErrorMessage ?></p>
                    </div>
                    <div class="modal-footer">
                        <a href="index.php" class="btn btn-secondary">OK</a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>