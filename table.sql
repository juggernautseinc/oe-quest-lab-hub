#IfNotRow background_services name Quest_Lab_Hub
INSERT INTO `background_services` (`name`, `title`, `active`, `running`, `next_run`, `execute_interval`, `function`, `require_once`, `sort_order`) VALUES
    ('Quest_Lab_Hub', 'Quest Lab Hub', 0, 0, '2023-01-01 08:00:00', 15, 'getResults', '/interface/modules/custom_modules/oe-quest-lab-hub/public/results.php', 100);
#EndIf
