<?php

require_once( 'Mail/mimeDecode.php' );
require_once( plugin_config_get( 'path_erp', NULL, TRUE ) . 'core/Mail/simple_html_dom.php');

class Mail_Parser
{
	var $_parse_html = FALSE;
	var $_parse_mime = FALSE;
	var $_mail_encoding = 'UTF-8';

	var $_file;
	var $_content;

	var $_from;
	var $_subject;
	var $_charset = 'auto';
	var $_priority;
	var $_transferencoding;
	var $_body;
	var $_parts = array ( );
	var $_ctype = array ( );

	var $_mb_list_encodings = array();

	function Mail_Parser( $options = array() )
	{
		$this->_parse_mime = $options['parse_mime'];
		$this->_parse_html = $options['parse_html'];
		$this->_mail_encoding = $options['mail_encoding'];

		$this->prepare_mb_list_encodings();
	}
	
	function prepare_mb_list_encodings()
	{
		if ( extension_loaded( 'mbstring' ) )
		{
			$t_charset_list = mb_list_encodings();

			$r_charset_list = array();
			foreach ( $t_charset_list AS $value )
			{
				$r_charset_list[ $value ] = strtolower( $value );
			}

			$this->_mb_list_encodings = $r_charset_list;
		}
	}

	function setInputString( $content )
	{
		$this->_content = $content;
	}

	function setInputFile( $file )
	{
		$this->_file = $file;
		$this->_content = file_get_contents( $this->_file );
	}

	function process_encoding( $encode )
	{
		if ( extension_loaded( 'mbstring' ) && $this->_mail_encoding !== $this->_charset )
		{
			$encode = mb_convert_encoding( $encode, $this->_mail_encoding, $this->_charset );
		}

		return( $encode );
	}

	function parse()
	{
		$decoder = new Mail_mimeDecode( $this->_content );

		$params['include_bodies'] = TRUE;
		$params['decode_bodies'] = TRUE;
		$params['decode_headers'] = TRUE;
		$params['rfc_822bodies'] = TRUE;

		$structure = $decoder->decode( $params );

		$this->parseStructure( $structure );
		unset( $this->_content );

		if ( extension_loaded( 'mbstring' ) )
		{
			$this->_from = $this->process_encoding( $this->_from );
			$this->_subject = $this->process_encoding( $this->_subject );
			$this->_body = $this->process_encoding( $this->_body );
		}
	}

	function from()
	{
		return $this->_from;
	}

	function subject()
	{
		return $this->_subject;
	}

	function priority()
	{
		return $this->_priority;
	}

	function body()
	{
		return $this->_body;
	}

	function parts()
	{
		return $this->_parts;
	}

	function parseStructure( $structure )
	{
		$this->setFrom( $structure->headers['from'] );
		$this->setSubject( $structure->headers['subject'] );
		$this->setContentType( $structure->ctype_primary, $structure->ctype_secondary );

		if ( isset( $structure->ctype_parameters[ 'charset' ] ) )
		{
			$this->setCharset( $structure->ctype_parameters[ 'charset' ] );
		}

 		if ( isset( $structure->headers['x-priority'] ) )
 		{
			$this->setPriority( $structure->headers['x-priority'] );
		}

		if ( isset( $structure->headers['content-transfer-encoding'] ) )
		{
			$this->setTransferEncoding( $structure->headers['content-transfer-encoding'] );
		}

		if ( isset( $structure->body ) )
		{
			$this->setBody( $structure->body );
		}

		if ( $this->_parse_mime && isset( $structure->parts ) )
		{
			$this->setParts( $structure->parts );
		}
	}

	function setFrom( $from )
	{
		$this->_from = $from;
	}

	function setSubject( $subject )
	{
		$this->_subject = $subject;
	}

	function setCharset( $charset )
	{
		if ( extension_loaded( 'mbstring' ) && $this->_charset === 'auto' )
		{
			$arraysearch_result = array_search( strtolower( $charset ), $this->_mb_list_encodings, TRUE );
			$this->_charset = ( ( $arraysearch_result !== FALSE ) ? $arraysearch_result : 'auto' );
		}
	}

	function setPriority( $priority )
	{
		$this->_priority = $priority;
	}

	function setTransferEncoding( $transferencoding )
	{
		$this->_transferencoding = $transferencoding;
	}
	
	function setContentType( $primary, $secondary )
	{
		$this->_ctype['primary'] = $primary;
		$this->_ctype['secondary'] = $secondary;
	}

	function setBody( $body )
	{
		if ( is_blank( $body ) || !is_blank( $this->_body ) )
		{
			return;
		}

		if ( 'text' === $this->_ctype['primary'] &&	'plain' === $this->_ctype['secondary'] )
		{
			$this->_body = $body;
		}
		elseif ( $this->_parse_html && 'text' === $this->_ctype['primary'] && 'html' === $this->_ctype['secondary'] )
		{
			$htmlToText = str_get_html( $body );

			// extract text from HTML
			$this->_body = $htmlToText->plaintext;
		}
	}

	function setParts( &$parts, $attachment = FALSE, $p_attached_email_subject = NULL )
	{
		$i = 0;

		if ( $attachment === TRUE && $p_attached_email_subject === NULL && !empty( $parts[ $i ]->headers[ 'subject' ] ) )
		{
			$p_attached_email_subject = $parts[ $i ]->headers[ 'subject' ];
		}

		if ( 'text' == $parts[ $i ]->ctype_primary && in_array( $parts[ $i ]->ctype_secondary, array( 'plain', 'html' ) ) )
		{
			$t_stop_part = FALSE;

			// Let's select the plaintext body if we can find it
			// It must only have 2 parts. Most likely one is text/html and one is text/plain
			if (
				count( $parts ) === 2 && !isset( $parts[ $i ]->parts ) && !isset( $parts[ $i+1 ]->parts ) &&
				'text' === $parts[ $i+1 ]->ctype_primary &&
				in_array( $parts[ $i+1 ]->ctype_secondary, array( 'plain', 'html' ) ) && 
				$parts[ $i ]->ctype_secondary !== $parts[ $i+1 ]->ctype_secondary
			)
			{
				if ( $parts[ $i ]->ctype_secondary !== 'plain' )
				{
					$i++;
				}

				$t_stop_part = TRUE;
			}

			$this->setContentType( $parts[$i]->ctype_primary, $parts[ $i ]->ctype_secondary );

			if ( isset( $parts[ $i ]->headers[ 'content-transfer-encoding' ] ) )
			{
				$this->setTransferEncoding( $parts[ $i ]->headers[ 'content-transfer-encoding' ] );
			}

			if ( isset( $parts[$i]->ctype_parameters[ 'charset' ] ) )
			{
				$this->setCharset( $parts[$i]->ctype_parameters[ 'charset' ] );
			}

			if ( $attachment === TRUE )
			{
				$this->addPart( $parts[ $i ], $p_attached_email_subject );
			}
			else
			{
				$this->setBody( $parts[ $i ]->body );
			}

			if ( $t_stop_part === TRUE )
			{
				return;
			}

			$i++;
		}
		for ( $i; $i < count( $parts ); $i++ )
		{
			if ( 'multipart' == $parts[ $i ]->ctype_primary )
			{
				$this->setParts( $parts[ $i ]->parts, $attachment, $p_attached_email_subject );
			}
			elseif ( 'message' == $parts[ $i ]->ctype_primary && $parts[ $i ]->ctype_secondary === 'rfc822' )
			{
				$this->setParts( $parts[ $i ]->parts, TRUE );
			}
			else
			{
				$this->addPart( $parts[ $i ] );
			}
		}
	}
	
	function addPart( &$part, $p_alternative_name = NULL )
	{
		$p[ 'ctype' ] = $part->ctype_primary . "/" . $part->ctype_secondary;

		if ( isset( $part->ctype_parameters[ 'name' ] ) ) {
			$p[ 'name' ] = $part->ctype_parameters[ 'name' ];
		}
		elseif ( isset( $part->headers[ 'content-disposition' ] ) && strpos( $part->headers[ 'content-disposition' ], 'filename="' ) !== FALSE )
		{
			$t_start = strpos( $part->headers[ 'content-disposition' ], 'filename="' ) + 10;
			$t_end = strpos( $part->headers[ 'content-disposition' ], '"', $t_start );
			$p[ 'name' ] = substr( $part->headers[ 'content-disposition' ], $t_start, ( $t_end - $t_start ) );
		}
		elseif ( isset( $part->headers[ 'content-type' ] ) && strpos( $part->headers[ 'content-type' ], 'name="' ) !== FALSE )
		{
			$t_start = strpos( $part->headers[ 'content-type' ], 'name="' ) + 6;
			$t_end = strpos( $part->headers[ 'content-type' ], '"', $t_start );
			$p[ 'name' ] = substr( $part->headers[ 'content-type' ], $t_start, ( $t_end - $t_start ) );
		}
		elseif ( 'text' == $part->ctype_primary && in_array( $part->ctype_secondary, array( 'plain', 'html' ) ) && !empty( $p_alternative_name ) )
		{
			$p[ 'name' ] = $p_alternative_name . ( ( $part->ctype_secondary === 'plain' ) ? '.txt' : '.html' );
		}

		$p[ 'body' ] = $part->body;

		if ( extension_loaded( 'mbstring' ) && !empty( $p[ 'name' ] ) )
		{
			if ( isset( $part->ctype_parameters[ 'charset' ] ) )
			{
				$this->setCharset( $part->ctype_parameters[ 'charset' ] );
			}

			$p[ 'name' ] = $this->process_encoding( $p[ 'name' ] );
		}

		$this->_parts[] = $p;
	}
}

?>