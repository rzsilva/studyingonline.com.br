<?php
return;
	// Includes    
	include_once("./EnviaEmail.php");	
    
	// Recuperando valores do html
	$name = addslashes((isset($_POST['name'])) ? $_POST['name'] : ''); 
	$email = addslashes((isset($_POST['email'])) ? $_POST['email'] : ''); 
	$phone = addslashes((isset($_POST['phone'])) ? $_POST['phone'] : ''); 
	$message = addslashes((isset($_POST['message'])) ? $_POST['message'] : '');
	// monta mensam
	$mensagemHTML = "Nome: " . $name . "<br><br>" . "E-mail: " . $email . "<br><br>" . "Telefone: " . $phone . "<br><br>" ."Mensagem: " . $message;
	// envia email
	$env = new EnviaEmail();
	// $env->Send("site", "rafael_zacche@hotmail.com", $subject, $mensagemHTML);
	$env->Send("no-reply", "comercial@adaline.com.br", "Contato www.studyingonline.com.br", $mensagemHTML);
		
	header("Location: ./index.html#contact");
?>