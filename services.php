<?php
$vpjtqwv = "vpjtqwv";

include_once "init.php";

$editingTitle = $page["editingTitle"];

$editingDescription = $page["editingDescription"];

$editingImage = $page["pageImage"];
?>







<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="utf-8">
    <meta name="keywords" content="Editing Writing &amp;amp; Rewriting">
    <meta name="description" content="">
    <title>Services</title>
    <link rel="stylesheet" href="nicepage.css" media="screen">
<link rel="stylesheet" href="Services.css" media="screen">
    <script class="u-script" type="text/javascript" src="jquery-1.9.1.min.js" defer=""></script>
    <script class="u-script" type="text/javascript" src="nicepage.js" defer=""></script>
    <meta name="generator" content="Nicepage 5.10.4, nicepage.com">
    <link id="u-theme-google-font" rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto:100,100i,300,300i,400,400i,500,500i,700,700i,900,900i|Open+Sans:300,300i,400,400i,500,500i,600,600i,700,700i,800,800i">
    
    
    
    
    <script type="application/ld+json">{
		"@context": "http://schema.org",
		"@type": "Organization",
		"name": "",
		"logo": "images/media_pitchlogo.jpg"
}</script>
    <meta name="theme-color" content="#478ac9">
    <meta property="og:title" content="Services">
    <meta property="og:type" content="website">
  <meta data-intl-tel-input-cdn-path="intlTelInput/">
</head>

  <style>
    .u-section-3 .u-text-1 {
        font-size: 0.875rem;
        margin: 47% 10% 0;
    }
    </style>


<body class="u-body u-xl-mode" data-lang="en">
    <?php include_once "navbar.php"; ?>



    <section class="u-clearfix u-container-align-center u-image u-section-1" id="sec-c7b1" data-image-width="4608" data-image-height="3456" style="background-image:url('<?php echo $editingImage; ?>');">
      <div class="u-clearfix u-sheet u-sheet-1"></div>
    </section>




    
    <section class="u-clearfix u-section-2" id="sec-aeac">
  <div class="u-clearfix u-sheet u-sheet-1">
    <div class="u-clearfix u-expanded-width u-layout-wrap u-layout-wrap-1">
      <div class="u-layout">
        <div class="u-layout-row">
          <div class="u-container-style u-layout-cell u-size-30 u-layout-cell-1">
            <div class="u-container-layout u-container-layout-1">
              <h2 class="u-align-center u-text u-text-default u-text-1"><?php echo $editingTitle; ?></h2>
            </div>
          </div>
          <div class="u-container-style u-layout-cell u-size-30 u-layout-cell-2">
            <div class="u-container-layout u-container-layout-2">
              <p class="u-align-center u-text u-text-default u-text-2"><?php echo $editingDescription; ?></p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>









   






    <?php include_once 'circle.php'; ?>


    <section class="skrollable u-clearfix u-image u-parallax u-section-2" id="sec-dd27" data-image-width="1280" data-image-height="857">
      <div class="u-clearfix u-sheet u-valign-middle u-sheet-1">
        <div class="u-clearfix u-expanded-width u-layout-wrap u-layout-wrap-1">
          <div class="u-layout">
            <div class="u-layout-row">
              <div class="u-container-style u-layout-cell u-size-60 u-white u-layout-cell-1">
                <div class="u-container-layout u-container-layout-1">
                  <p class="u-align-center u-text u-text-default u-text-1" style="font-size: 1.5rem;font-weight:bold;"><?php echo $finalText; ?></p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>



    <?php include_once 'footer.php'; ?>




</body>