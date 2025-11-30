<?php
/**
 * List of monitored websites for the status monitor.
 * 
 * Each website is represented as an associative array with the following keys:
 *  - 'name' : A human-readable name for the website/service.
 *  - 'url'  : The full URL to check the status of.
 * 
 * You can add more sites by adding additional arrays inside the main array.
 * Example:
 * [
 *     'name' => 'ExampleSite',
 *     'url'  => 'https://example.com',
 * ]
 */

return [
    [
        'name' => 'My Website',          // Display name of the service
        'url'  => 'https://example.com', // URL to check for availability
    ],
    // Add more sites below as needed
    // [
    //     'name' => 'AnotherSite',
    //     'url'  => 'https://anothersite.com',
    // ],
];
