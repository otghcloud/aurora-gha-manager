<?php

return [

    'image_builder_path' => env('IMAGE_BUILDER_PATH', dirname(base_path()).'/aurora-gha-manager-templates'),
    'log_directory' => env('BUILD_LOG_DIRECTORY', storage_path('app/builds')),
    'working_directory' => env('BUILD_WORKING_DIRECTORY', storage_path('app')),

    'packer_plugin_path' => env('PACKER_PLUGIN_PATH', storage_path('app/packer-plugins')),
    'packer_binary' => env('PACKER_BINARY', 'packer'),

    'runner_images_path' => env('RUNNER_IMAGES_PATH', storage_path('app/runner-images')),
    'runner_images_repository' => env('RUNNER_IMAGES_REPOSITORY', 'https://github.com/actions/runner-images.git'),

    'templates_install_path' => env('TEMPLATES_INSTALL_PATH', storage_path('app/templates')),
    'templates_repository' => env('TEMPLATES_REPOSITORY', 'https://github.com/otghcloud/aurora-gha-manager-templates'),

];
