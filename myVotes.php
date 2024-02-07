<?php
try {
    $logFilePath = "logs/log" . date("d-m-Y") . ".txt";
    if (!file_exists(dirname($logFilePath))) {
        mkdir(dirname($logFilePath), 0755, true);
    }
    $filePathParts = explode("/", __FILE__);
} catch (Exception $e) {
    echo "<script>errorNotification('ERROR al crear el archivo de log -> " . $e->getMessage() . "')</script>";
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Votos | ¡Vota!</title>
    <meta name="description" content="Verifica tu dirección de email para acceder a 'Vota!'">
    <link rel="stylesheet" type="text/css" href="styles.css">
    <link rel="shortcut icon" href="/icons/faviconDark.svg" type="image/svg">
    <link rel="shortcut icon" href="/icons/favicon.svg" type="image/svg" media="(prefers-color-scheme: light)">
    <script src="https://code.jquery.com/jquery-3.7.1.js"
        integrity="sha256-eKhayi8LEQwp4NKxN+CfCh+3qOVUtJn3QNZ0TciWLP4=" crossorigin="anonymous"></script>
    <script src="/componentes/notificationHandler.js"></script>
</head>

<body id="conditions">
    <?php
    include_once("common/header.php");
    if (!isset($_SESSION["usuario"])) {
        header("HTTP/1.1 403 Forbidden");
        exit();
    }

    if (isset($_POST["password"])) {
        try {
            $password = $_POST["password"];
            $passhash = hash('sha512', $password);

            require 'data/dbAccess.php';
            $pdo = new PDO("mysql:host=$hostname;dbname=$dbname", $username, $pw);

            $query = $pdo->prepare("SELECT * FROM User WHERE user_pass = :pass AND user_id = :id;");
            $query->execute([':pass' => $passhash, ':id' => $_SESSION["usuario"]]);
            $row = $query->fetch();

            echo "<br>User:<br>";
            var_dump($row);
            echo "<br>";

            if ($query->rowCount() == 0) {
                $logTxt = "\n[" . end($filePathParts) . " ― " . date('H:i:s') . " ― PASSWORD INCORRECT]: El usuario con user_id= " . $_SESSION["usuario"] . " ha intentado ver sus botos pero ha introducido una contraseña incorrecta\n";
                file_put_contents($logFilePath, $logTxt, FILE_APPEND);
                echo "<script>errorNotification('Contraseña incorrecta')</script>";
                ?>
                <main>
                    <h2>Introduce tu contraseña para ver tus votos</h2>
                    <form method="POST">
                        <label for="password">Contraseña:</label>
                        <input type="password" id="password" name="password" required>
                        <input type="submit" value="Ver mis votos">
                    </form>
                </main>

            <?php } else {
                $email = $row["user_mail"];
                $encryptString = $row["encryptString"];
                $decryptString = openssl_decrypt($encryptString, "AES-256-CTR", $password);

                echo "<br>EncryptString: $encryptString<br>";
                echo "<br>Email: $email<br>";

                $queryInvitations = $pdo->prepare("SELECT * FROM Invitation WHERE mail_to = :mail;");
                $queryInvitations->execute([':mail' => $email]);

                if ($queryInvitations->rowCount() == 0) {
                    $logTxt = "\n[" . end($filePathParts) . " ― " . date('H:i:s') . " ― NO INVITATIONS]: El usuario $email no tiene votaciones\n";
                    file_put_contents($logFilePath, $logTxt, FILE_APPEND);
                    echo "<h2>Aun no has votado en ninguna encuesta</h2>";
                } else {

                    while ($rowInvitations = $queryInvitations->fetch()) {
                        // rowInvitations -> todas las invitaciones que tiene el usuario
    
                        $invitationId = $rowInvitations["invitation_id"];
                        $isDone = $rowInvitations["is_survey_done"];
                        $surveyId = $rowInvitations["survey_id"];

                        echo "<br>Invitation: $invitationId<br>";
                        echo "<br>IsDone: $isDone<br>";
                        echo "<br>Survey: $surveyId<br>";

                        $surveyQuery = $pdo->prepare("SELECT * FROM Survey WHERE survey_id = :id;");
                        $surveyQuery->execute([':id' => $surveyId]);

                        if ($surveyQuery->rowCount() == 0) {
                            $logTxt = "\n[" . end($filePathParts) . " ― " . date('H:i:s') . " ― NO SURVEY]: No se ha encontrado la encuesta con id= " . $row["survey_id"] . "\n";
                            file_put_contents($logFilePath, $logTxt, FILE_APPEND);
                            echo "<script>errorNotification('ERROR al encontrar la encuesta')</script>";
                        } else {
                            $surveyRow = $surveyQuery->fetch();
                            $surveyTitle = $surveyRow["survey_title"];

                            $query = $pdo->prepare(
                                "SELECT `i`.`mail_to`, `i`.`survey_id`, `v`.`option_id`, `i`.`invitation_id`
                                FROM UserVote v
                                INNER JOIN Invitation i ON `v`.`invitation_id_enc` = aes_encrypt(concat(convert(`i`.`invitation_id`, char), :decryptString), :pass)
                                WHERE `i`.`mail_to` = (SELECT user_mail FROM User WHERE user_id = :user_id);"
                            );
                            $query->execute([
                                ":user_id" => $_SESSION["usuario"],
                                ":decryptString" => $decryptString,
                                ":pass" => $password
                            ]);
                            // $query = $pdo->prepare("select `i`.`survey_id`, `v`.`option_id`
                            // from `UserVote` `v`, `Invitation` `i`
                            // where `i`.`mail_to` = :email
                            // and `v`.`invitation_id_enc` = aes_encrypt(concat(cast( :invitation_id as char), :decryptString), :pass);");
    
                            // $query->execute([
                            //     ':email' => $email,
                            //     ':invitation_id' => $invitationId,
                            //     ':decryptString' => $decryptString,
                            //     ':pass' => $password
                            // ]);
                            if ($query->rowCount() == 0) {
                                $logTxt = "\n[" . end($filePathParts) . " ― " . date('H:i:s') . " ― NO VOTES]: El usuario $email no ha votado en la encuesta '$surveyTitle' (survey_id = $surveyId)\n";
                                file_put_contents($logFilePath, $logTxt, FILE_APPEND);
                                echo "<script>errorNotification('Aun no has votado en la encuesta $surveyTitle')</script>";
                            } else {
                                $row = $query->fetch();
                                $optionId = $row["option_id"];

                                $optionQuery = $pdo->prepare("SELECT * FROM SurveyOption WHERE option_id = :id;");
                                $optionQuery->execute([':id' => $optionId]);

                                if ($optionQuery->rowCount() == 0) {
                                    $logTxt = "\n[" . end($filePathParts) . " ― " . date('H:i:s') . " ― NO OPTION]: No se ha encontrado la opción con id= " . $optionId . "\n";
                                    file_put_contents($logFilePath, $logTxt, FILE_APPEND);
                                    echo "<script>errorNotification('ERROR al encontrar la opción')</script>";
                                } else {
                                    $optionRow = $optionQuery->fetch();
                                    $optionText = $optionRow["option_text"];

                                    echo "<div class='voto'>";
                                    echo "<h2>$surveyTitle</h2>";
                                    echo "<p>$optionText</p>";
                                    echo "</div>";
                                }
                            }
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            $logTxt = "\n[" . end($filePathParts) . " ― " . date('H:i:s') . " ― ERROR db connect]: ERROR al conectarse con la base de datos -> " . $e->getMessage() . "\n";
            file_put_contents($logFilePath, $logTxt, FILE_APPEND);
            echo "<script>errorNotification('ERROR al conectarse con la base de datos')</script>";
        }
    } else {
        ?>
        <main>
            <h2>Introduce tu contraseña para ver tus votos</h2>
            <form method="POST">
                <label for="password">Contraseña:</label>
                <input type="password" id="password" name="password" required>
                <input type="submit" value="Ver mis votos">
            </form>
        </main>
    <?php } ?>

    <ul id="notification__list"></ul>
    <div class="footer">
        <?php include_once("common/footer.php") ?>
    </div>
</body>

</html>