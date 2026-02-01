<?php
// Permitir que React se conecte desde cualquier lado (CORS)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

// DATOS DE CONEXIÓN (Cámbialos por los de tu hosting real)
$host = "localhost";
$user = "root";      // Usuario de MySQL
$pass = "";          // Contraseña de MySQL
$db_name = "coolstore_db";

$conn = new mysqli($host, $user, $pass, $db_name);

if ($conn->connect_error) {
    die(json_encode(["error" => "No se pudo conectar a la BD: " . $conn->connect_error]));
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// --- 1. OBTENER PRODUCTOS (GET) ---
if ($method === 'GET' && $action === 'products') {
    $sql = "SELECT * FROM products ORDER BY id DESC";
    $result = $conn->query($sql);
    $products = [];

    while($row = $result->fetch_assoc()) {
        $pId = $row['id'];
        
        // Sacar variantes (fotos)
        $varSql = "SELECT color, mica, img_url as img FROM variants WHERE product_id = $pId";
        $varRes = $conn->query($varSql);
        $variantes = [];
        while($v = $varRes->fetch_assoc()) $variantes[] = $v;

        // Sacar tallas
        $sizeSql = "SELECT talla FROM sizes WHERE product_id = $pId";
        $sizeRes = $conn->query($sizeSql);
        $tallas = [];
        while($s = $sizeRes->fetch_assoc()) $tallas[] = $s['talla'];

        $row['variantes'] = $variantes;
        $row['tallas'] = $tallas;
        
        // Convertir tipos numéricos
        $row['precio'] = (float)$row['precio'];
        $row['precio_mayorista'] = (float)$row['precio_mayorista'];
        $row['stock'] = (int)$row['stock'];
        $row['descuento'] = (int)$row['descuento'];

        $products[] = $row;
    }
    echo json_encode($products);
    exit;
}

// --- 2. CREAR PEDIDO (POST) ---
if ($method === 'POST' && $action === 'create_order') {
    $data = json_decode(file_get_contents("php://input"), true);
    
    $code = "#ORD-" . rand(10000, 99999);
    $nombre = $conn->real_escape_string($data['shipping']['nombre']);
    $email = $conn->real_escape_string($data['shipping']['email']);
    $direccion = $conn->real_escape_string($data['shipping']['direccion']);
    $ciudad = $conn->real_escape_string($data['shipping']['ciudad'] ?? '');
    $cp = $conn->real_escape_string($data['shipping']['cp'] ?? '');
    $total = $data['total'];
    $metodo = $data['paymentMethod'];

    // Simulación de estatus de pago
    $estatusPago = ($metodo === 'card') ? 'pagado' : 'pendiente';

    $sqlOrder = "INSERT INTO orders (order_code, cliente_nombre, cliente_email, direccion, ciudad, cp, total, metodo_pago, estatus) 
                 VALUES ('$code', '$nombre', '$email', '$direccion', '$ciudad', '$cp', $total, '$metodo', '$estatusPago')";
    
    if ($conn->query($sqlOrder)) {
        $orderId = $conn->insert_id;

        foreach ($data['items'] as $item) {
            $prodId = $item['id'];
            $qty = $item['cantidad'];
            $precio = $item['precio'];
            $varInfo = $conn->real_escape_string($item['variante']['color'] . " / " . $item['talla']);
            $prodName = $conn->real_escape_string($item['nombre']);

            // Guardar Item
            $sqlItem = "INSERT INTO order_items (order_id, product_id, nombre_producto, cantidad, precio_unitario, variante_info)
                        VALUES ($orderId, $prodId, '$prodName', $qty, $precio, '$varInfo')";
            $conn->query($sqlItem);

            // Descontar Stock
            $conn->query("UPDATE products SET stock = GREATEST(0, stock - $qty) WHERE id = $prodId");
        }

        echo json_encode(["success" => true, "orderCode" => $code]);
    } else {
        echo json_encode(["success" => false, "error" => $conn->error]);
    }
    exit;
}

// --- 3. GUARDAR PRODUCTO (ADMIN) ---
if ($method === 'POST' && $action === 'save_product') {
    $data = json_decode(file_get_contents("php://input"), true);

    $nombre = $conn->real_escape_string($data['nombre']);
    $marca = $conn->real_escape_string($data['marca']);
    $cat = $conn->real_escape_string($data['categoria']);
    $genero = $conn->real_escape_string($data['genero']);
    $precio = $data['precio'];
    $mayorista = $data['precioMayorista'];
    $stock = $data['stock'];
    $desc = $data['descuento'];
    $detalles = $conn->real_escape_string($data['descripcion']);

    if (isset($data['id']) && $data['id'] > 100000) { 
        // Es un ID temporal (nuevo producto) -> INSERT
        $sql = "INSERT INTO products (nombre, marca, categoria, genero, precio, precio_mayorista, stock, descuento, descripcion)
                VALUES ('$nombre', '$marca', '$cat', '$genero', $precio, $mayorista, $stock, $desc, '$detalles')";
        
        if ($conn->query($sql)) {
            $newId = $conn->insert_id;
            
            // Guardar Variante (Foto)
            $img = $conn->real_escape_string($data['imagen']); // Base64
            $color = $conn->real_escape_string($data['color']);
            $conn->query("INSERT INTO variants (product_id, color, img_url) VALUES ($newId, '$color', '$img')");

            // Guardar Tallas
            foreach ($data['tallas'] as $t) {
                $t = $conn->real_escape_string($t);
                $conn->query("INSERT INTO sizes (product_id, talla) VALUES ($newId, '$t')");
            }
            
            echo json_encode(["success" => true]);
        } else {
            echo json_encode(["success" => false, "error" => $conn->error]);
        }
    } else {
        // Es un producto existente -> UPDATE (Simplificado)
        $id = $data['id'];
        $sql = "UPDATE products SET nombre='$nombre', stock=$stock, precio=$precio WHERE id=$id";
        $conn->query($sql);
        echo json_encode(["success" => true, "message" => "Actualizado"]);
    }
    exit;
}

$conn->close();
?>