<?php
// Aktifkan error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once("network.php");

// Pastikan koneksi ke database berhasil
if (!isset($koneksi) || !$koneksi) {
    die(json_encode(array('message' => 'error!', 'error' => 'Koneksi gagal: ' . mysqli_connect_error())));
}

header('Content-Type: application/json');

// Ambil data dari parameter GET atau set default ke null jika tidak ada
$k_ultrasonic = isset($_GET['ket_ultrasonic']) ? trim($_GET['ket_ultrasonic']) : null;
$k_pressure = isset($_GET['kedalaman']) ? trim($_GET['kedalaman']) : null;
$ina219_tegangan = isset($_GET['tegangan']) ? trim($_GET['tegangan']) : null;
$dht21_suhu = isset($_GET['suhu']) ? trim($_GET['suhu']) : null;

// Set baterai ke null jika ina219_tegangan null, jika tidak, hitung nilainya
if (!is_null($ina219_tegangan) && $ina219_tegangan > 13) {
    $ina219_tegangan = 13;
}
$baterai = is_null($ina219_tegangan) ? null : (($ina219_tegangan - 11.6) / (13 - 11.6)) * 100;
$ultrasonic = (383-$k_ultrasonic);
$pressure = ($k_pressure-4.2);
// Masukkan data mentah ke tabel rawsensor
$query_rawsensor = "
    INSERT INTO rawsensor (sensor_ultrasonic, sensor_submersible, sensor_tegangan, sensor_suhu)
    VALUES (?, ?, ?, ?)
";
$stmt_rawsensor = mysqli_prepare($koneksi, $query_rawsensor);
if ($stmt_rawsensor) {
    mysqli_stmt_bind_param($stmt_rawsensor, "dddd", $ultrasonic, $pressure, $ina219_tegangan, $dht21_suhu);
    if (!mysqli_stmt_execute($stmt_rawsensor)) {
        error_log("Error executing statement for rawsensor: " . mysqli_stmt_error($stmt_rawsensor));
        echo json_encode(array('message' => 'error!', 'error' => 'Error executing statement for rawsensor.'));
        mysqli_stmt_close($stmt_rawsensor);
        mysqli_close($koneksi);
        exit;
    }
    mysqli_stmt_close($stmt_rawsensor);
} else {
    error_log("Error preparing statement for rawsensor: " . mysqli_error($koneksi));
    echo json_encode(array('message' => 'error!', 'error' => 'Query preparation for rawsensor failed.'));
    mysqli_close($koneksi);
    exit;
}

// Validasi data sensor dan set nilai ke NULL jika tidak valid
function validate_sensor($value, $min, $max) {
    return is_numeric($value) && $value > $min && $value < $max ? floatval($value) : null;
}

$ultrasonic = validate_sensor($ultrasonic, 0, 383);
$pressure = validate_sensor($pressure, 0, 383);
$ina219_tegangan = is_numeric($ina219_tegangan) ? floatval($ina219_tegangan) : null;
$dht21_suhu = is_numeric($dht21_suhu) ? floatval($dht21_suhu) : null;

// Fungsi untuk validasi dengan moving window
function validate_with_moving_window($koneksi, $table, $column, $value, $window_size = 2, $absolute_threshold = 8) {
    // Ambil data dari tabel untuk moving window
    $query = "SELECT $column FROM $table ORDER BY date DESC LIMIT ?";
    $stmt = mysqli_prepare($koneksi, $query);
    mysqli_stmt_bind_param($stmt, "i", $window_size);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $past_value);

    $values = [];
    while (mysqli_stmt_fetch($stmt)) {
        if ($past_value !== null) {
            $values[] = $past_value;
        }
    }
    mysqli_stmt_close($stmt);

    // Jika jumlah data valid kurang dari window_size, anggap valid
    if (count($values) < $window_size) {
        return true; // Tidak cukup data untuk moving window, anggap valid
    }

    // Hitung rata-rata dari data
    $average = array_sum($values) / count($values);

    // Hitung perubahan absolut antara nilai baru dan rata-rata
    $absolute_change = abs($value - $average);

    // Tentukan apakah perubahan dalam batas absolut
    return $absolute_change <= $absolute_threshold;
}


// Validasi data sensor dan set nilai ke NULL jika tidak valid
$ultrasonic = validate_with_moving_window($koneksi, 'sensor_ultrasonic', 'ket_ultrasonic', $ultrasonic, 4, 5) ? floatval($ultrasonic) : null;
$pressure = validate_with_moving_window($koneksi, 'sensor_submersible', 'kedalaman', $pressure, 4, 5) ? floatval($pressure) : null;
// Ambil nilai threshold dari tabel
$sql = "SELECT batas_siaga, batas_bahaya FROM threshold_status ORDER BY date DESC LIMIT 1";
$result = $koneksi->query($sql);

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $batas_siaga = $row['batas_siaga'];
    $batas_bahaya = $row['batas_bahaya'];

    // Tentukan status untuk ultrasonic
    $status_ultrasonic = is_null($ultrasonic) ? "Error" :
        (($ultrasonic >= 0 && $ultrasonic < $batas_siaga) ? "Normal" :
        (($ultrasonic >= $batas_siaga && $ultrasonic < $batas_bahaya) ? "Siaga" :
        (($ultrasonic >= $batas_bahaya) ? "Bahaya" : "Unknown")));

    // Tentukan status untuk pressure
    $status_pressure = is_null($pressure) ? "Error" :
        (($pressure >= 0 && $pressure < $batas_siaga) ? "Normal" :
        (($pressure >= $batas_siaga && $pressure < $batas_bahaya) ? "Siaga" :
        (($pressure >= $batas_bahaya) ? "Bahaya" : "Unknown")));

    // Log status untuk debugging
    error_log("Status Ultrasonic: $status_ultrasonic");
    error_log("Status Pressure: $status_pressure");

   // Masukkan data ke tabel sensor_ultrasonic
    $query_ultrasonic = "INSERT INTO sensor_ultrasonic (ket_ultrasonic, status) VALUES (?, ?)";
    $stmt_ultrasonic = mysqli_prepare($koneksi, $query_ultrasonic);
    if ($stmt_ultrasonic) {
        mysqli_stmt_bind_param($stmt_ultrasonic, "ds", $ultrasonic, $status_ultrasonic);
        if (!mysqli_stmt_execute($stmt_ultrasonic)) {
            error_log("Error executing statement for sensor_ultrasonic: " . mysqli_stmt_error($stmt_ultrasonic));
            echo json_encode(array('message' => 'error!', 'error' => 'Error executing statement for sensor_ultrasonic.'));
            mysqli_stmt_close($stmt_ultrasonic);
            mysqli_close($koneksi);
            exit;
        }
        mysqli_stmt_close($stmt_ultrasonic);
    } else {
        error_log("Error preparing statement for sensor_ultrasonic: " . mysqli_error($koneksi));
        echo json_encode(array('message' => 'error!', 'error' => 'Query preparation for sensor_ultrasonic failed.'));
        mysqli_close($koneksi);
        exit;
    }

    // Masukkan data ke tabel sensor_submersible
    $query_pressure = "INSERT INTO sensor_submersible (kedalaman, status) VALUES (?, ?)";
    $stmt_pressure = mysqli_prepare($koneksi, $query_pressure);
    if ($stmt_pressure) {
        mysqli_stmt_bind_param($stmt_pressure, "ds", $pressure, $status_pressure);
        if (!mysqli_stmt_execute($stmt_pressure)) {
            error_log("Error executing statement for sensor_submersible: " . mysqli_stmt_error($stmt_pressure));
            echo json_encode(array('message' => 'error!', 'error' => 'Error executing statement for sensor_submersible.'));
            mysqli_stmt_close($stmt_pressure);
            mysqli_close($koneksi);
            exit;
        }
        mysqli_stmt_close($stmt_pressure);
    } else {
        error_log("Error preparing statement for sensor_submersible: " . mysqli_error($koneksi));
        echo json_encode(array('message' => 'error!', 'error' => 'Query preparation for sensor_submersible failed.'));
        mysqli_close($koneksi);
        exit;
    }

    // Masukkan data ke tabel voltage_baterai
    $query_voltage = "INSERT INTO voltage_baterai (tegangan) VALUES (?)";
    $stmt_voltage = mysqli_prepare($koneksi, $query_voltage);
    if ($stmt_voltage) {
        mysqli_stmt_bind_param($stmt_voltage, "d", $ina219_tegangan);
        if (!mysqli_stmt_execute($stmt_voltage)) {
            error_log("Error executing statement for voltage_baterai: " . mysqli_stmt_error($stmt_voltage));
            echo json_encode(array('message' => 'error!', 'error' => 'Error executing statement for voltage_baterai.'));
            mysqli_stmt_close($stmt_voltage);
            mysqli_close($koneksi);
            exit;
        }
        mysqli_stmt_close($stmt_voltage);
    } else {
        error_log("Error preparing statement for voltage_baterai: " . mysqli_error($koneksi));
        echo json_encode(array('message' => 'error!', 'error' => 'Query preparation for voltage_baterai failed.'));
        mysqli_close($koneksi);
        exit;
    }

    // Masukkan data ke tabel sensor_suhu
    $query_temperature = "INSERT INTO sensor_suhu (suhu) VALUES (?)";
    $stmt_temperature = mysqli_prepare($koneksi, $query_temperature);
    if ($stmt_temperature) {
        mysqli_stmt_bind_param($stmt_temperature, "d", $dht21_suhu);
        if (!mysqli_stmt_execute($stmt_temperature)) {
            error_log("Error executing statement for sensor_suhu: " . mysqli_stmt_error($stmt_temperature));
            echo json_encode(array('message' => 'error!', 'error' => 'Error executing statement for sensor_suhu.'));
            mysqli_stmt_close($stmt_temperature);
            mysqli_close($koneksi);
            exit;
        }
        mysqli_stmt_close($stmt_temperature);
    } else {
        error_log("Error preparing statement for sensor_suhu: " . mysqli_error($koneksi));
        echo json_encode(array('message' => 'error!', 'error' => 'Query preparation for sensor_suhu failed.'));
        mysqli_close($koneksi);
        exit;
    }

    // Masukkan data ke tabel sisa_baterai
    $query_battery = "INSERT INTO sisa_baterai (persen) VALUES (?)";
    $stmt_battery = mysqli_prepare($koneksi, $query_battery);
    if ($stmt_battery) {
        mysqli_stmt_bind_param($stmt_battery, "d", $baterai);
        if (!mysqli_stmt_execute($stmt_battery)) {
            error_log("Error executing statement for sisa_baterai: " . mysqli_stmt_error($stmt_battery));
            echo json_encode(array('message' => 'error!', 'error' => 'Error executing statement for sisa_baterai.'));
            mysqli_stmt_close($stmt_battery);
            mysqli_close($koneksi);
            exit;
        }
        mysqli_stmt_close($stmt_battery);
    } else {
        error_log("Error preparing statement for sisa_baterai: " . mysqli_error($koneksi));
        echo json_encode(array('message' => 'error!', 'error' => 'Query preparation for sisa_baterai failed.'));
        mysqli_close($koneksi);
        exit;
    }

    // Fungsi untuk menghitung kenaikan
    function calculate_increase($koneksi, $table, $column) {
        $today = date('Y-m-d');
        $one_day_ago = date('Y-m-d', strtotime('-1 day'));
        $one_week_ago = date('Y-m-d', strtotime('-1 week'));
        $one_month_ago = date('Y-m-d', strtotime('-1 month'));

        // Ambil data untuk perhitungan harian
        $query_daily = "SELECT $column FROM $table WHERE DATE(date) = ?";
        $stmt_daily = mysqli_prepare($koneksi, $query_daily);
        mysqli_stmt_bind_param($stmt_daily, "s", $today);
        mysqli_stmt_execute($stmt_daily);
        mysqli_stmt_bind_result($stmt_daily, $daily_value);
        $daily_values = [];
        while (mysqli_stmt_fetch($stmt_daily)) {
            $daily_values[] = $daily_value;
        }
        mysqli_stmt_close($stmt_daily);

        $daily_increase = !empty($daily_values) ? end($daily_values) - $daily_values[0] : 0;

        // Ambil data untuk perhitungan mingguan
        $query_weekly = "SELECT $column FROM $table WHERE DATE(date) BETWEEN ? AND ?";
        $stmt_weekly = mysqli_prepare($koneksi, $query_weekly);
        mysqli_stmt_bind_param($stmt_weekly, "ss", $one_week_ago, $today);
        mysqli_stmt_execute($stmt_weekly);
        mysqli_stmt_bind_result($stmt_weekly, $weekly_value);
        $weekly_values = [];
        while (mysqli_stmt_fetch($stmt_weekly)) {
            $weekly_values[] = $weekly_value;
        }
        mysqli_stmt_close($stmt_weekly);

        $weekly_increase = !empty($weekly_values) ? end($weekly_values) - $weekly_values[0] : 0;

        // Ambil data untuk perhitungan bulanan
        $query_monthly = "SELECT $column FROM $table WHERE DATE(date) BETWEEN ? AND ?";
        $stmt_monthly = mysqli_prepare($koneksi, $query_monthly);
        mysqli_stmt_bind_param($stmt_monthly, "ss", $one_month_ago, $today);
        mysqli_stmt_execute($stmt_monthly);
        mysqli_stmt_bind_result($stmt_monthly, $monthly_value);
        $monthly_values = [];
        while (mysqli_stmt_fetch($stmt_monthly)) {
            $monthly_values[] = $monthly_value;
        }
        mysqli_stmt_close($stmt_monthly);

        $monthly_increase = !empty($monthly_values) ? end($monthly_values) - $monthly_values[0] : 0;

        return [$daily_increase, $weekly_increase, $monthly_increase];
    }

    $today = date('Y-m-d');

    // Hitung kenaikan untuk sensor ultrasonic
    list($daily_increase_ultrasonic, $weekly_increase_ultrasonic, $monthly_increase_ultrasonic) = calculate_increase($koneksi, 'sensor_ultrasonic', 'ket_ultrasonic');

    // Masukkan data kenaikan ke tabel data_increases untuk ultrasonic
    $query_increase_ultrasonic = "
        INSERT INTO data_increases (date, sensor_type, daily_increase, weekly_increase, monthly_increase)
        VALUES (?, 'ultrasonic', ?, ?, ?)
    ";
    $stmt_increase_ultrasonic = mysqli_prepare($koneksi, $query_increase_ultrasonic);
    if ($stmt_increase_ultrasonic) {
        mysqli_stmt_bind_param($stmt_increase_ultrasonic, "sddd", $today, $daily_increase_ultrasonic, $weekly_increase_ultrasonic, $monthly_increase_ultrasonic);
        if (!mysqli_stmt_execute($stmt_increase_ultrasonic)) {
            error_log("Error executing statement for data_increases (ultrasonic): " . mysqli_stmt_error($stmt_increase_ultrasonic));
            echo json_encode(array('message' => 'error!', 'error' => 'Error executing statement for data_increases (ultrasonic).'));
            mysqli_stmt_close($stmt_increase_ultrasonic);
            mysqli_close($koneksi);
            exit;
        }
        mysqli_stmt_close($stmt_increase_ultrasonic);
    } else {
        error_log("Error preparing statement for data_increases (ultrasonic): " . mysqli_error($koneksi));
        echo json_encode(array('message' => 'error!', 'error' => 'Query preparation for data_increases (ultrasonic) failed.'));
        mysqli_close($koneksi);
        exit;
    }

    // Hitung kenaikan untuk sensor submersible
    list($daily_increase_submersible, $weekly_increase_submersible, $monthly_increase_submersible) = calculate_increase($koneksi, 'sensor_submersible', 'kedalaman');

    // Masukkan data kenaikan ke tabel data_increases untuk submersible
    $query_increase_submersible = "
        INSERT INTO data_increases (date, sensor_type, daily_increase, weekly_increase, monthly_increase)
        VALUES (?, 'submersible', ?, ?, ?)
    ";
    $stmt_increase_submersible = mysqli_prepare($koneksi, $query_increase_submersible);
    if ($stmt_increase_submersible) {
        mysqli_stmt_bind_param($stmt_increase_submersible, "sddd", $today, $daily_increase_submersible, $weekly_increase_submersible, $monthly_increase_submersible);
        if (!mysqli_stmt_execute($stmt_increase_submersible)) {
            error_log("Error executing statement for data_increases (submersible): " . mysqli_stmt_error($stmt_increase_submersible));
            echo json_encode(array('message' => 'error!', 'error' => 'Error executing statement for data_increases (submersible).'));
            mysqli_stmt_close($stmt_increase_submersible);
            mysqli_close($koneksi);
            exit;
        }
        mysqli_stmt_close($stmt_increase_submersible);
    } else {
        error_log("Error preparing statement for data_increases (submersible): " . mysqli_error($koneksi));
        echo json_encode(array('message' => 'error!', 'error' => 'Query preparation for data_increases (submersible) failed.'));
        mysqli_close($koneksi);
        exit;
    }

    echo json_encode(array('message' => 'success!'));
} else {
    echo json_encode(array('message' => 'error!', 'error' => 'Tidak dapat mengambil data threshold.'));
}

mysqli_close($koneksi);
?>
