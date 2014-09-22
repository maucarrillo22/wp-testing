<?php

class WpTesting_TestPasser extends WpTesting_Doer
{

    /**
     * @var WpTesting_WordPressFacade
     */
    private $wp = null;

    public function __construct(WpTesting_WordPressFacade $wp)
    {
        $this->wp = $wp;
    }

    public function addContentFilter()
    {
        $object = $this->wp->getQuery()->get_queried_object();
        if ($object instanceof WP_Post && $object->post_type == 'wpt_test') {
            $this->wp->addFilter('the_content', array($this, 'renderTestContent'));
        }
        return $this;
    }

    public function renderTestContent($content)
    {
        $content .= '<h1>Questions with answers</h1>';
        // customize here as template (allow themes overrides)
        return $content;
    }

}
