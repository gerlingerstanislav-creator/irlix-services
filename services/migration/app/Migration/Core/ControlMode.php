<?php
namespace App\Migration\Core;

/** Worker-local opt-in; old jobs and every existing API remain strict. */
final class ControlMode
{
    public static bool $enabled = false;
}
