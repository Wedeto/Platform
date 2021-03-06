<?php
/*
This is part of Wedeto, the WEb DEvelopment TOolkit.
It is published under the MIT Open Source License.

Copyright 2017, Egbert van der Wal

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/

namespace Wedeto\Application\Task;

use Wedeto\Util\Hook;
use Wedeto\Application\CLI\CLI;
use Wedeto\Application\Application;
use Wedeto\Application\Module\Manager as ModuleManager;

use Wedeto\Log\Logger;
use Wedeto\Log\Writer\StreamWriter;

/**
 * The TaskRunner collects and runs tasks. It is also used by the scheduler to
 * run periodic jobs
 */
class TaskRunner
{
    /** The list of available tasks */
    protected $task_list = array();

    /** If the tasks were already located */
    protected $init = false;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Add a task to the list of registered tasks 
     * @param $task string The class name of the task
     * @param $description string The description of the task
     */
    public function registerTask(string $task, string $description)
    {
        $this->task_list[$task] = $description;
    }

    /** 
     * Find tasks in all registered modules. This method calls registerTasks on
     * all modules that have been registered, giving them the opportunity to register
     * their tasks.
     */
    private function findTasks()
    {
        if ($this->init)
            return;

        $resolver = $this->app->moduleManager;
        $modules = $resolver->getModules();
        foreach ($modules as $mod)
            $mod->registerTasks($this);

        // Provide a way to register tasks using a hook
        Hook::execute("Wedeto.Application.Task.TaskRunner.findTasks", ['taskrunner' => $this]);
        $this->init = true;
    }

    /** 
     * List the registered tasks
     * @param $ostr resource The output stream to write to. Defaults to STDOUT
     */
    public function listTasks($ostr = STDOUT)
    {
        $this->findTasks();
        if (count($this->task_list) === 0)
        {
			// @codeCoverageIgnoreStart
            fprintf($ostr, "No tasks available\n");
			// @codeCoverageIgnoreEnd
        }
        else
        {
            fprintf($ostr, "Listing available tasks: \n");

            foreach ($this->task_list as $task => $desc)
            {
                $task = str_replace('\\', ':', $task);
                fprintf($ostr, "- %-30s\n", $task);
                CLI::formatText(32, CLI::MAX_LINE_LENGTH, $desc, $ostr);
            }
            printf("\n");
        }
    }

    /**
     * Run the specified task.
     *
     * @param string $task The task to run - classname. It may use : rather
     *                     than \ as a namespace separator
     * @param resource $ostr The stream to send output to
     * @return bool True on success, false on failure
     */
    public function run(string $task, $ostr = STDERR)
    {
        // CLI uses : because \ is used as escape character, so that
        // awkward syntax is required.
        $task = str_replace(":", "\\", $task);

        $log = Logger::getLogger('');
        $log->addLogWriter(new StreamWriter(STDOUT));

        if (!class_exists($task))
        {
            fprintf($ostr, "Error: task does not exist: {$task}\n");
            return false;
        }

        try
        {
            if (!is_subclass_of($task, TaskInterface::class))
            {
                fprintf($ostr, "Error: invalid task: {$task}\n");
                return false;
            }
            $taskrunner = new $task;
            $taskrunner->execute();
        }
        catch (\Throwable $e)
        {
            fprintf($ostr, "Error: error while running task: %s\n", $task);
            fprintf($ostr, "Exception: %s\n", get_class($e));
            fprintf($ostr, "Message: %s\n", $e->getMessage());
            if (method_exists($e, "getLine"))
                fprintf($ostr, "On: %s (line %d)\n", $e->getFile(), $e->getLine());
            fprintf($ostr, $e->getTraceAsString() . "\n");
            return false;
        }
        return true;
    }
}
