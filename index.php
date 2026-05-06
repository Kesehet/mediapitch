<?php
$vpjtqwv = "vpjtqwv";

include_once "init.php";


$home = $data["home"];

$carousel = $home["carousel"];

shuffle($carousel);

$carouselInitIndex = mt_rand(0, count($carousel)-1);

if($carouselInitIndex == 1){
  $carouselInitIndex = count($carousel)-1;
}





$welcomeText = "Welcome to Media Pitch, one-stop solution for all your media-related needs.";

$finalText = "Ready to take your content to the next level? Trust Media Pitch for top-notch editing, writing, and rewriting services that make an impact. Contact us today to discuss your project and let us unleash the power of words for you.";



?>

<html>


<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="utf-8">
    <meta name="keywords" content="For The Best, Content Related, Solutions">
    <meta name="description" content="">
    <title>Home</title>
    <link rel="stylesheet" href="nicepage.css" media="screen">
<link rel="stylesheet" href="Home.css" media="screen">
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
    <meta property="og:title" content="Home">
    <meta property="og:type" content="website">
  <meta data-intl-tel-input-cdn-path="intlTelInput/">
</head>


<style>

  .sliderText {
    opacity: 0; /* Set initial opacity to 0 */
    animation: gentleBlink <?php echo $carouselSpeed;?>s ease-in-out forwards infinite; /* Apply the slide-in animation */
    
  }
  .sliderImg {
    opacity: 0; /* Set initial opacity to 0 */
    animation: gentleBlinkImg <?php echo $carouselSpeed;?>s ease-in-out forwards infinite; /* Apply the slide-in animation */
  }
  .for {
    transform: scale(100%);
    animation: fora <?php echo $carouselSpeed;?>s ease-in-out forwards infinite;
    font-weight:400;
  }
  .the{
    animation: the <?php echo $carouselSpeed;?>s ease-in-out forwards infinite;
    font-weight:400;
  }
  .best{
    animation: best <?php echo $carouselSpeed;?>s ease-in-out forwards infinite;
    font-weight:400;
  }
  .sol{
    animation: sol <?php echo $carouselSpeed;?>s ease-in-out forwards infinite;
    font-weight:400;
  }
 
    @keyframes gentleBlinkImg {
        0% {
            transform: scale(99%);
            opacity: 0;
        }
        35% {
            opacity: 1;
            transform: scale(95%);
        }
        80% {
            transform: scale(99%);
            opacity: 1;
            
        }
        100% {
            opacity: 0; 
        }
    }
    @keyframes gentleBlink {
        0% {
            font-weight:400;
            transform: scale(150%);
            opacity: 0;
        }
        20% {
            transform: scale(80%);
        }
        35% {
            
            opacity: 1;
            transform: scale(95%);
        }
        45%{
            font-weight:400;
        }
        49%{
            font-weight:<?php echo $boldPeak; ?>;
        }


        /* 60%{
            font-weight:<?php echo $boldPeak; ?>;
        }
        64%{
            font-weight:400;
        }
        68%{
            font-weight:<?php echo $boldPeak; ?>;
        } */
        75% {
          font-weight:<?php echo $boldPeak; ?>;
        }
        79% {
          font-weight:400;
        }
        80% {
            transform: scale(100%);
            opacity: 1;
        
        }
        100% {
            transform: scale(0%);
            opacity: 0; 
            font-weight:400;
        }
    }
    @keyframes fora {
        4% {
            font-weight:<?php echo $boldPeak; ?>;
            
        }
        8% {
            font-weight:400;   
        }
        100%{
            font-weight:400;
        }
    }
    @keyframes the {
        0% {
            font-weight:400;
        
        }
        20% {
            font-weight:400;   
        }
        24% {
            font-weight:<?php echo $boldPeak; ?>;   
        }
        28% {
            font-weight:400;   
        }
        100%{
            font-weight:400;
        }
    }
    @keyframes best {
        0% {
            font-weight:400;
        }
        35% {
            font-weight:400;   
        }
        39% {
            font-weight:<?php echo $boldPeak; ?>;   
        }
        44% {
            font-weight:400;   
        }
        100%{
            font-weight:400;
        }
    }
    @keyframes sol {
        0% {
            font-weight:400;
        }
        80% {
            font-weight:400;   
        }
        84% {
            font-weight:<?php echo $boldPeak; ?>;   
        }
        94% {
            font-weight:<?php echo $boldPeak; ?>;   
        }
        98% {
            font-weight:400;   
        }
        100%{
            font-weight:400;
        }
    }
</style>

<script>
  const CAROUSEL = <?php echo json_encode($carousel);?>;
  var currCarouselIndex = <?php echo $carouselInitIndex; ?>;
  var sliderTexts = document.getElementsByClassName('sliderText-reff');

  function changeCarouselSlide() {
    var sliderImg = document.getElementById('sliderImg');
    var currentIndex = currCarouselIndex;
    var nextIndex = (currentIndex + 1) % CAROUSEL.length;
    currCarouselIndex = nextIndex;

    for (let i = 0; i < sliderTexts.length; i++) {
        const sliderText = sliderTexts[i];
        sliderText.classList.remove('sliderText');  
    }
    sliderImg.classList.remove('sliderImg');


    sliderImg.src = CAROUSEL[nextIndex].i;
    for (let i = 0; i < sliderTexts.length; i++) {
        const sliderText = sliderTexts[i];
        sliderText.innerHTML = CAROUSEL[nextIndex].t;  
    }
  

    for (let i = 0; i < sliderTexts.length; i++) {
        const sliderText = sliderTexts[i];
        sliderText.classList.add('sliderText');  
    }
    sliderImg.classList.add('sliderImg');
  }
    

  // Set interval to change the carousel slide every 2 seconds
  setInterval(changeCarouselSlide, <?php echo $carouselSpeed*1000; ?>);
</script>






<body class="u-body u-xl-mode" data-lang="en">
<?php include_once "navbar.php"; ?>








<section class="u-clearfix u-valign-middle u-section-1" id="sec-9d32">
      <div class="u-clearfix u-gutter-0 u-layout-wrap u-layout-wrap-1">
        <div class="u-layout">
          <div class="u-layout-row">
            <div class="u-container-style u-layout-cell u-size-30 u-layout-cell-1">
              <div class="u-container-layout u-container-layout-1">
                <img id="sliderImg" class="u-align-center u-image u-image-default u-image-1 sliderImg" src="<?php echo $carousel[$carouselInitIndex]["i"]; ?>" alt="" data-image-width="1280" data-image-height="853">
                <img class="u-align-center u-expanded-width-xl u-image u-image-contain u-image-default u-preserve-proportions u-image-2" src="images/Untitleddesign7.png" alt="" data-image-width="3375" data-image-height="3375">
                <h2 class="u-align-center u-hidden-lg u-hidden-md u-hidden-xl u-text u-text-1" data-animation-name="" data-animation-duration="0" data-animation-delay="0" data-animation-direction=""><b class="for">For</b> <b class="the">The</b> <b class="best">Best</b></h2>
                <h1 class="u-align-center sliderText-reff sliderText u-hidden-lg u-hidden-md u-hidden-xl u-text u-text-palette-1-base u-text-2"><?php echo $carousel[$carouselInitIndex]["t"]; ?></h1>
                <h2 class="u-align-center u-hidden-lg u-hidden-md u-hidden-xl u-text u-text-3" data-animation-name="" data-animation-duration="0" data-animation-delay="0" data-animation-direction=""><b class="sol">Solutions</b></h2>
              </div>
            </div>
            <div class="u-container-style u-hidden-sm u-hidden-xs u-layout-cell u-size-30 u-layout-cell-2">
              <div class="u-container-layout u-valign-middle u-container-layout-2">
                <h2 class="u-align-center u-text u-text-4" data-animation-name="" data-animation-duration="0" data-animation-delay="0" data-animation-direction=""><b class="for">For</b> <b class="the">The</b> <b class="best">Best</b></h2>
                <h1 class="u-align-center sliderText-reff sliderText u-text u-text-palette-1-base u-text-5"><?php echo $carousel[$carouselInitIndex]["t"]; ?></h1>
                <h2 class="u-align-center u-text u-text-6" data-animation-name="" data-animation-duration="0" data-animation-delay="0" data-animation-direction=""><b class="sol">Solutions</b></h2>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>





    <section class="skrollable u-clearfix u-image u-parallax u-section-2" id="sec-dd27" data-image-width="1280" data-image-height="857">
      <div class="u-clearfix u-sheet u-valign-middle u-sheet-1">
        <div class="u-clearfix u-expanded-width u-layout-wrap u-layout-wrap-1">
          <div class="u-layout">
            <div class="u-layout-row">
              <div class="u-container-style u-layout-cell u-size-60 u-white u-layout-cell-1">
                <div class="u-container-layout u-container-layout-1">
                  <p class="u-align-center u-text u-text-default u-text-1" style="font-size: 2.5rem;"><?php echo $welcomeText; ?></p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>







    <?php include_once "circle.php"; ?>


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


<?php include_once "footer.php"; ?>







</body>





</html>