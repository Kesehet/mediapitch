
<style>
    .image-container {
        background-position: 50% 50%;
        background-size: cover;
        background-repeat: no-repeat;
        background-attachment: scroll;
        background-clip: border-box;
        background-origin: padding-box;
        min-height: 400px;
    }
</style>
<!-- THE GREAT CIRCLE -->
<style>
}
  .alienImage{
    animation: circleBlink <?php echo $carouselSpeed;?>s ease-in-out forwards infinite; /* Apply the slide-in animation */
  }
  .alienImage:hover{
      background:#dddddd22;
      animation: circleBlinkHoveredrotate <?php echo $carouselSpeed;?>s ease-in-out forwards infinite; /* Apply the slide-in animation */
  }
  
  @keyframes circleBlink {
        0% {
            
            opacity:1;
        }
        35% {
            opacity:0.95;
            
        }
        80% {
            opacity:0.87;
            
            
        }
        100% {
            opacity:1;
            
        }
    }
    @keyframes circleBlinkHovered {
        0% {
            
            opacity:1;
        }
        35% {
            
            opacity:0.95;
            
        }
        80% {
            opacity:0.87;
            
            
        }
        100% {
            opacity:1;
            
        }
    }
    
    @keyframes circleBlinkHoveredrotate {
        0% {
            transform:rotateZ(0deg);
            opacity:1;
        }
        35% {
            transform:rotateZ(0.1deg);
            opacity:0.95;
            
        }
        80% {
            transform:rotateZ(-0.1deg);
            opacity:0.87;
            
            
        }
        100% {
            opacity:1;
            transform:rotateZ(0deg);
        }
    }
    
</style>


<?php 
$imagesWithText = (getValueOrDefault($SERVICE_KEYWORD,"") != "" ? $page["circleArray"]:$data["home"]["circleArray"]);

shuffle($imagesWithText);

foreach ($imagesWithText as $ctr => $imageWithText) {
    if(isset($imageWithText['image2'])){
        if (rand(1,2) == 2){
            $imageWithText['image'] = $imageWithText['image2'];
        }
    }
    //php to generate random between 1 to 5
    $random_radius = "border-radius:".rand(1,5)."0% ".rand(1,5)."0% ".rand(1,5)."0% ".rand(1,5)."0%; ";
    $random_button_radius = "border-radius: ".rand(10,20)."px ".rand(10,20)."px ".rand(10,20)."px ".rand(10,20)."px; ";
    if($imageWithText['link'] != "" && $imageWithText['link'] != "#"){
        $button_html = '<button onclick="window.location.href=\'' . $imageWithText['link'] . '\'" class="u-align-left u-btn u-button-style u-hover-palette-1-dark-1 u-palette-1-base u-btn-1" style="'.$random_button_radius.'" >Learn More</button>';
    }

    $html = '<section>
        <div class="u-clearfix u-sheet u-sheet-1" style="
          padding:5%;
        "
        >
            <div class="u-clearfix u-expanded-width u-layout-wrap u-layout-wrap-1">
                <div class="u-layout">
                    <div class="u-layout-row">';

    if ($ctr % 2 == 0) {
        $html .= '<div class="u-container-style u-layout-cell u-size-30 u-layout-cell-1">
                    <div class="u-container-layout u-container-layout-1" style="padding:5%;">
                        <h4 class="u-align-left u-text u-text-default u-text-1">' . $imageWithText['text'] . '</h4>
                        <p class="u-align-left u-text u-text-default u-text-2">' . $imageWithText['description'] . '</p>
                        '.(isset($button_html) ? $button_html:'').'
                        
                    </div>
                </div>

                <div class="u-container-style u-layout-cell u-size-30 u-layout-cell-1 image-container" style="background-image: url(' . $imageWithText['image'] . ');'.$random_radius.'">
                </div>';
    } else {
        $html .= '<div class="u-container-style u-layout-cell u-size-30 u-layout-cell-1 image-container" style="background-image: url(' . $imageWithText['image'] . ');'.$random_radius.'">
                </div>

                <div class="u-container-style u-layout-cell u-size-30 u-layout-cell-1">
                    <div class="u-container-layout u-container-layout-1" style="padding:5%;">
                        <h4 class="u-align-left u-text u-text-default u-text-1">' . $imageWithText['text'] . '</h4>
                        <p class="u-align-left u-text u-text-default u-text-2">' . $imageWithText['description'] . '</p>
                        '.(isset($button_html) ? $button_html:'').'
                    </div>
                </div>';
    }

    $html .= '</div>
            </div>
        </div>
    </div>
</section>';

    echo $html;
}

?>
<!-- END THE GREAT CIRCLE -->


