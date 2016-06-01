<?php

/**
 * Description of SimpleJobsController
 *
 * @author Koala
 */
class SimpleJobsController extends Controller
{
    private static $url_handlers = array(
        'simple-jobs/$Action/$ID/$OtherID' => 'handleAction',
    );

    public function index()
    {
        $actions = self::$allowed_actions;

        $message = array(
            'message' => 'You must choose an action',
            'allowed_actions' => $actions
        );
        return $this->sendAsJson($message);
    }
}