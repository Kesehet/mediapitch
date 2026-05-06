<?php 
if (!isset($vpjtqwv)){
    exit();
}



$links = [
    [
        'url' => 'index.php',
        'text' => 'Home',
    ],
    [
        'url' => 'services.php?for-the-best=Editing-Writing-Rewriting',
        'text' => 'Services',
        'submenu' => [
            [
                'url' => 'services.php?for-the-best=Editing-Writing-Rewriting',
                'text' => 'Editing Writing & Rewriting',
            ],
            [
                'url' => 'services.php?for-the-best=Designing',
                'text' => 'Designing',
            ],
            [
                'url' => 'services.php?for-the-best=Photo-Editing',
                'text' => 'Photo Editing',
            ],
            [
                'url' => 'services.php?for-the-best=Social-Media',
                'text' => 'Social Media',
            ],
            [
                'url' => 'services.php?for-the-best=Website-Designing',
                'text' => 'Website Designing',
            ],
            [
                'url' => 'services.php?for-the-best=App-Development',
                'text' => 'App Development',
            ],
            [
                'url' => 'services.php?for-the-best=Cloud-Infrastructure',
                'text' => 'Cloud Infrastructure',
            ],
            [
                'url' => 'services.php?for-the-best=Marketing',
                'text' => 'Marketing',
            ],
            [
                'url' => 'services.php?for-the-best=Trade-Shows',
                'text' => 'Trade Shows',
            ],
            [
                'url' => 'services.php?for-the-best=Public-Relations',
                'text' => 'Public Relations',
            ],
            [
                'url' => 'services.php?for-the-best=Translation-Transcription',
                'text' => 'Translation & Transcription',
            ],
            [
                'url' => 'services.php?for-the-best=Book-Publishing',
                'text' => 'Book Publishing',
            ]
            
        ],
    ],
    [
        'url' => 'about-us.php',
        'text' => 'About Us',
    ],
    [
        'url' => 'contact-us.php',
        'text' => 'Contact Us',
    ],
];

usort($links[1]['submenu'], function($a, $b) {
    return strcasecmp($a['text'], $b['text']);
});





?>


<header class="u-clearfix u-header u-header" id="sec-1ac2">
    <div class="u-clearfix u-sheet u-sheet-1">
        <a href="index.php" class="u-image u-logo u-image-1" data-image-width="731" data-image-height="644">
          <img src="images/media_pitchlogo.jpg" class="u-logo-image u-logo-image-1">
        </a>
        <nav class="u-menu u-menu-dropdown u-offcanvas u-menu-1">
          <div class="menu-collapse" style="font-size: 1rem; letter-spacing: 0px;">
            <a class="u-button-style u-custom-left-right-menu-spacing u-custom-padding-bottom u-custom-text-hover-color u-custom-top-bottom-menu-spacing u-nav-link u-text-active-palette-1-base u-text-hover-palette-2-base" href="#">
              <svg class="u-svg-link" viewBox="0 0 24 24"><use xlink:href="#menu-hamburger"></use></svg>
              <svg class="u-svg-content" version="1.1" id="menu-hamburger" viewBox="0 0 16 16" x="0px" y="0px" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns="http://www.w3.org/2000/svg"><g><rect y="1" width="16" height="2"></rect><rect y="7" width="16" height="2"></rect><rect y="13" width="16" height="2"></rect>
</g></svg>
            </a>
          </div>
          <div class="u-custom-menu u-nav-container">
            <ul class="u-nav u-unstyled u-nav-1">
                <?php
                

                foreach ($links as $link) {
                    echo '<li class="u-nav-item">';
                    echo '<a class="u-button-style u-nav-link u-text-active-palette-1-base u-text-hover-grey-80" href="' . $link['url'] . '" style="padding: 10px 20px;">' . $link['text'] . '</a>';
                    if (isset($link['submenu']) && is_array($link['submenu'])) {
                        echo '<div class="u-nav-popup">';
                        echo '<ul class="u-h-spacing-20 u-nav u-unstyled u-v-spacing-10">';
                        foreach ($link['submenu'] as $submenu) {
                            echo '<li class="u-nav-item">';
                            echo '<a class="u-button-style u-hover-grey-5 u-nav-link u-white" href="' . $submenu['url'] . '">' . $submenu['text'] . '</a>';
                            echo '</li>';
                        }
                        echo '</ul>';
                        echo '</div>';
                    }
                    echo '</li>';
                }
                ?>
            </ul>
          </div>
          <div class="u-custom-menu u-nav-container-collapse">
            <div class="u-black u-container-style u-inner-container-layout u-opacity u-opacity-95 u-sidenav">
              <div class="u-inner-container-layout u-sidenav-overflow">
                <div class="u-menu-close"></div>
                <ul class="u-align-center u-nav u-popupmenu-items u-unstyled u-nav-3">
                    <?php
                    foreach ($links as $link) {
                        echo '<li class="u-nav-item">';
                        echo '<a class="u-button-style u-nav-link" href="' . $link['url'] . '">' . $link['text'] . '</a>';
                        if (isset($link['submenu']) && is_array($link['submenu'])) {
                            echo '<div class="u-nav-popup">';
                            echo '<ul class="u-h-spacing-20 u-nav u-unstyled u-v-spacing-10">';
                            foreach ($link['submenu'] as $submenu) {
                                echo '<li class="u-nav-item">';
                                echo '<a class="u-button-style u-hover-grey-5 u-nav-link" href="' . $submenu['url'] . '">' . $submenu['text'] . '</a>';
                                echo '</li>';
                            }
                            echo '</ul>';
                            echo '</div>';
                        }
                        echo '</li>';
                    }
                    ?>
                </ul>
              </div>
            </div>
            <div class="u-black u-menu-overlay u-opacity u-opacity-70"></div>
          </div>
        </nav>
      </div>
</header>
