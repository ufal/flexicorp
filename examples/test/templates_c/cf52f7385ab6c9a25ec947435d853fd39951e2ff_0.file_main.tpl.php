<?php
/* Smarty version 5.3.1, created on 2024-10-07 14:24:49
  from 'file:main.tpl' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.3.1',
  'unifunc' => 'content_6703ef31cb71a6_50855185',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    'cf52f7385ab6c9a25ec947435d853fd39951e2ff' => 
    array (
      0 => 'main.tpl',
      1 => 1728311088,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
))) {
function content_6703ef31cb71a6_50855185 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = '/var/www/html/teitok/shared/templates';
?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" "http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" style='height: 100%; padding: 0; margin: 0;'><head>
  <meta http-equiv="content-type" content="text/html; charset=UTF-8">
  <meta name="description" content="description">
  <meta name="keywords" content="keywords"> 
  <meta name="author" content="author">
  <meta name="robots" content="noindex,nofollow">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <base href="/teitok/shared/">
  <link href='https://fonts.googleapis.com/css?family=Open Sans' rel='stylesheet'>
  <link rel="stylesheet" type="text/css" href="https://corpora.unav.edu/teitok.css" media="screen">
  <link rel="stylesheet" type="text/css" href="Resources/xmlstyles.css" media="screen">
  <link rel="stylesheet" type="text/css" href="/teitok/shared/Resources/htmlstyles.css" media="screen">
  <link rel="stylesheet" type="text/css" href="/teitok/shared/Resources/xmlstyles.css" media="screen">
  <link rel="stylesheet" type="text/css" href="Resources/htmlstyles.css" media="screen">
  <link rel="shortcut icon" href="favicon.ico">
  <title><?php echo $_smarty_tpl->getValue('title');?>
</title>
</head>
<body style='height: 100%; padding: 0; margin: 0;'>
	<div style='position: fixed; padding: 0; margin: 0; left: 0; top: 0; width: 100%; height: 100%; z-index: 1; opacity: 0.7;'>&nbsp;</div>
	<div style="position: fixed; background-color: white;margin-left: 180px; width: 100%; height: 100%; opacity: 0.95; z-index: 2;">&nbsp;</div>	

	<div style="width: 180px; height: 100%; text-align: center; border-right: 0.5px solid #cc6600; position: fixed; top: 0px; left: 0px; z-index: 100;" id=menu>
        	<div style='font-size: 28px; font-weight: bold; color: black; padding-top: 10px; padding-bottom: 0px; text-align: center;'><?php echo $_smarty_tpl->getValue('title');?>
</div>
        	<div style='font-size: 18px; padding-top: 0px; padding-bottom: 10px; text-align: center;'><?php echo $_smarty_tpl->getValue('langtxt');?>
</div>
			<?php echo $_smarty_tpl->getValue('menu');?>

	</div>

     <div id="main" style=" min-width: 800px; position: absolute; top: 0px; padding-top: 5px;  padding-right: 15px; padding-left: 200px; padding-bottom: 50px; height: 100%; z-index: 50; opacity: 1; ">
		<?php echo $_smarty_tpl->getValue('maintext');?>

	</div>


</body></html>
<?php }
}
