<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitfdf1ab76ed227878be09c9c598f1e4f6
{
    public static $prefixLengthsPsr4 = array (
        'J' => 
        array (
            'Juggernaut\\Quest\\Module\\' => 24,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Juggernaut\\Quest\\Module\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitfdf1ab76ed227878be09c9c598f1e4f6::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitfdf1ab76ed227878be09c9c598f1e4f6::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInitfdf1ab76ed227878be09c9c598f1e4f6::$classMap;

        }, null, ClassLoader::class);
    }
}