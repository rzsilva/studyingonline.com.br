<?php

class EnviaEmail
{
	public function Send($de, $para, $assunto, $msg, $CC = null, $CCO = null)
	{
		$quebra_linha = $this->VerificaOS();		
		if( !strstr($de, "@"))
			$de = $de . "@" . $_SERVER['HTTP_HOST'];		
		$headers = $this->SetHeader($quebra_linha, $de);		
		return  mail(trim($para), $assunto, $msg, $headers, "-r". trim($de));
	}
	
	private function VerificaOS()
	{
		$quebra_linha = "";		
		/* Verifica qual é o sistema operacional do servidor para ajustar o cabeçalho de forma correta. Não alterar */
		if(PHP_OS == "Linux") 
			$quebra_linha = "\n"; //Se for Linux
		elseif(PHP_OS == "WINNT") 
			$quebra_linha = "\r\n"; // Se for Windows
		else 
			return null;
		 
		 return $quebra_linha;
	}
	
	private function SetHeader($quebra_linha, $de, $CC = null, $CCO = null )
	{
		/* Montando o cabeçalho da mensagem */
		$headers = "MIME-Version: 1.1" . $quebra_linha;
		$headers .= "Content-type: text/html; charset=utf-8".$quebra_linha; // Contém "text/html", sem essa linha, a mensagem não chegará formatada.		
		$headers .= "From: ".trim($de).$quebra_linha;
		$headers .= "Return-Path: " . trim($de).$quebra_linha;		
		// Esses dois "if's" abaixo são porque o Postfix obriga que se um cabeçalho for especificado, deverá haver um valor.
		// Se não houver um valor, o item não deverá ser especificado.
		if(strlen($CC) > 0) 
			$headers .= "Cc: ".trim($CC).$quebra_linha;
		if(strlen($CCO) > 0) 
			$headers .= "Bcc: ".trim($CCO).$quebra_linha;		
		$headers .= "Reply-To: ".trim($de).$quebra_linha; // Note que o e-mail do remetente será usado no campo Reply-To (Responder Para)		
		return $headers;
	}
}

?>