<?
if($sid) {
	//$view = sql_fetch("select * from data_list where id='$sid' ");
}
$view[vr360url] = "vr360_1.jpg";

?>
<script src="http://ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js"></script>
<link rel="stylesheet" href="https://cdn.pannellum.org/2.3/pannellum.css"/>
<script type="text/javascript" src="https://cdn.pannellum.org/2.3/pannellum.js"></script> 
<style> 
	
	#panorama { width: 1200px; height: 690px; position: relative; z-index: 1000;
		display: table; -moz-transform-origin: top left; -webkit-transform-origin: top left; -ms-transform-origin: top left; transform-origin: top left; } 


</style>

<script language = "javascript">  




function initScale(){ var ress = navigator.userAgent; if (ress.indexOf("Android 1.", 0) > -1 ){ //안드로이드2. 이하만 설정 
	if (ress.indexOf("480", 0) > -1 ) { // 480x800 var per = 0.5226824457593688; 
	} else 
		if(ress.indexOf("600", 0) > -1 ) { // 600 x 1024 var per = 0.681 
		} else if(ress.indexOf("1280", 0) > -1 ) { // 800 x 1280 var per = 0.631 
		} 
} else { 
	var dh = window.innerHeight; 
	var dw = window.innerWidth; 
	var cw = parseInt( $('#panorama').css('width') ); 
	var ch = parseInt( $('#panorama').css('height') ); 
	var per = dw/cw; var per2 =dh/ch; 
	if(per > per2 ){ per = per2; } 
	var gapH = ( dh - (ch*per) )/2; 
	var gapW = ( dw - (cw*per) )/2 
	} 
					 $("#panorama").css('transform', 'scale('+per+','+per+')'); $('body').css('margin-top', gapH ); 
					 $('body').css('margin-left', gapW ); 
					 curScale = per; 
					} 
	window.onresize = function(){ 
		initScale(); 
	} 
	window.onload = function() { 
		initScale();
	} </script>


<div id="panorama"></div>

<script>

// 아래 코드의 01.jpg 만 원하는 이미지로 교체한 후 URL 을 적어주면 됩니다

var sImageFileName = "../../uploads/360vr/<?=$view[vr360url]?>"; pannellum.viewer('panorama', { "type": "equirectangular", "panorama": sImageFileName }); </script>
