<?php
require_once(TOOLKIT . '/class.xsltpage.php');
require_once(TOOLKIT . '/class.administrationpage.php');
require_once(TOOLKIT . '/class.sectionmanager.php');
require_once(TOOLKIT . '/class.fieldmanager.php');
require_once(TOOLKIT . '/class.entrymanager.php');
require_once(TOOLKIT . '/class.entry.php');
require_once(EXTENSIONS . '/importcsv/lib/parsecsv-0.3.2/parsecsv.lib.php');
require_once(CORE . '/class.cacheable.php');

class contentExtensionImportcsvIndex extends AdministrationPage
{

    public function __construct(&$parent)
    {
        parent::__construct($parent);
        $this->setTitle('Symphony - Import / export CSV');
    }


    public function build()
    {
        parent::addStylesheetToHead(URL . '/extensions/importcsv/assets/importcsv.css', 'screen', 70);
        parent::addStylesheetToHead(URL . '/symphony/assets/forms.css', 'screen', 70);
        parent::build();
    }


    public function view()
    {
        if (isset($_POST['import-step-2']) && $_FILES['csv-file']['name'] != '') {
            // Import step 2:
            $this->__importStep2Page();
        } elseif (isset($_POST['import-step-3'])) {
            // Import step 3:
            $this->__importStep3Page();
        } elseif (isset($_REQUEST['export'])) {
            // Export:
            $this->__exportPage();
        } elseif (isset($_POST['ajax'])) {
            // Ajax import:
            // $this->__ajaxImport();
            $this->__ajaxImportRows();
        } else {
            // Startpage:
            $this->__indexPage();
        }
    }


    private function __indexPage()
    {
        // Create the XML for the page:
        $xml = new XMLElement('data');
        $sectionsNode = new XMLElement('sections');
        $sm = new SectionManager($this);
        $sections = $sm->fetch();
        foreach ($sections as $section)
        {
            $sectionsNode->appendChild(new XMLElement('section', $section->get('name'), array('id' => $section->get('id'))));
        }
        $xml->appendChild($sectionsNode);

        // Generate the HTML:
        $xslt = new XSLTPage();
        $xslt->setXML($xml->generate());
        $xslt->setXSL(EXTENSIONS . '/importcsv/content/index.xsl', true);
        $this->Form->setValue($xslt->generate());
        $this->Form->setAttribute('enctype', 'multipart/form-data');
    }

    /**
     * Get the CSV object as it is stored in the database.
     * @return bool|mixed   the CSV object on success, false on failure
     */
    private function __getCSV()
    {
        $cache = new Cacheable(Symphony::Database());
        $data  = $cache->check('importcsv');
        if($data != false)
        {
            return unserialize($data['data']);
        } else {
            return false;
        }
    }

    private function __importStep2Page()
    {
        // Store the CSV data in the cache table, so the CSV file will not be stored on the server
        $cache = new Cacheable(Symphony::Database());
        // Get the nodes provided by this CSV file:
        $csv = new parseCSV();
        $csv->auto($_FILES['csv-file']['tmp_name']);
        $cache->write('importcsv', serialize($csv), 60 * 60 * 24); // Store for one day

        $sectionID = $_POST['section'];

        // Generate the XML:
        $xml = new XMLElement('data', null, array('section-id' => $sectionID));

        // Get the fields of this section:
        $fieldsNode = new XMLElement('fields');
        $sm = new SectionManager($this);
        $section = $sm->fetch($sectionID);
        $fields = $section->fetchFields();
        foreach ($fields as $field)
        {
            $fieldsNode->appendChild(new XMLElement('field', $field->get('label'), array('id' => $field->get('id'))));
        }
        $xml->appendChild($fieldsNode);

        $csvNode = new XMLElement('csv');
        foreach ($csv->titles as $key)
        {
            $csvNode->appendChild(new XMLElement('key', $key));
        }
        $xml->appendChild($csvNode);

        // Generate the HTML:
        $xslt = new XSLTPage();
        $xslt->setXML($xml->generate());
        $xslt->setXSL(EXTENSIONS . '/importcsv/content/step2.xsl', true);
        $this->Form->setValue($xslt->generate());
    }


    private function __addVar($name, $value)
    {
        $this->Form->appendChild(new XMLElement('var', $value, array('class' => $name)));
    }

    private function __importStep3Page()
    {
        // Store the entries:
        $sectionID = $_POST['section'];
        $uniqueAction = $_POST['unique-action'];
        $uniqueField = $_POST['unique-field'];
        $countNew = 0;
        $countUpdated = 0;
        $countIgnored = 0;
        $countOverwritten = 0;
        $fm = new FieldManager($this);
        $csv = $this->__getCSV();

        // Load the information to start the importing process:
        $this->__addVar('section-id', $sectionID);
        $this->__addVar('unique-action', $uniqueAction);
        $this->__addVar('unique-field', $uniqueField);
        $this->__addVar('import-url', URL . '/symphony/extension/importcsv/');

        // Output the CSV-data:
        $csvData = $csv->data;
        $csvTitles = $csv->titles;
        $this->__addVar('total-entries', count($csvData));

        // Store the associated Field-ID's:
        $i = 0;
        $ids = array();
        foreach ($csvTitles as $title)
        {
            $ids[] = $_POST['field-'. $i];
            $i++;
        }
        $this->__addVar('field-ids', implode(',', $ids));


        /*
            // Have to put it all in HTML to prevent memory issues with larger CSV-files.
            $count = 0;
            $html = '';
            foreach ($csvData as $key => $data)
            {
                $html .= '<var class="csv-' . $count . '">';

                $i = 0;
                foreach ($data as $value)
                {
                    $associatedFieldID = $_POST['field-' . $i];
                    if ($associatedFieldID != 0) {
                        $unique = $i == $uniqueField ? 'yes' : 'no';
                        $html .= '<var field="' . $associatedFieldID . '" unique="' . $unique . '">' . $value . '</var>';
                    }
                    $i++;
                }
                $html .= '</var>';
                $count++;
            }
            $this->Form->appendChild(new XMLElement('div', $html));
        */
        $this->addScriptToHead(URL . '/extensions/importcsv/assets/import.js');
        $this->Form->appendChild(new XMLElement('h2', __('Import in progress...')));
        $this->Form->appendChild(new XMLElement('div', '<div class="bar"></div>', array('class' => 'progress')));
        $this->Form->appendChild(new XMLElement('div', null, array('class' => 'console')));
    }


    /**
     * Check to see if there exists an entry with a certain value and returns the ID of it.
     * Note: This only works if the field-type stores it's data in a field called 'value'.
     * @param    $value        string    The value to search for
     * @param    $fieldID    int        The ID of the field.
     * @return    mixed                The ID of the entry or null if no entry is found
     */
    private function __scanDatabase($value, $fieldID, $drivers)
    {
        /*
        $result = Symphony::Database()->fetch('DESCRIBE `tbl_entries_data_' . $fieldID . '`;');
        foreach ($result as $tableColumn)
        {
            if ($tableColumn['Field'] == 'value') {
                $searchResult = Symphony::Database()->fetchVar('entry_id', 0, 'SELECT `entry_id` FROM `tbl_entries_data_' . $fieldID . '` WHERE `value` = \'' . addslashes(trim($value)) . '\';');
                if ($searchResult != false) {
                    return $searchResult;
                } else {
                    return null;
                }

            }
        }
        */




        return null;
    }


    private function getDrivers()
    {
        $classes = glob(EXTENSIONS . '/importcsv/drivers/*.php');
        $drivers = array();
        foreach ($classes as $class)
        {
            include_once($class);
            $a = explode('_', str_replace('.php', '', basename($class)));
            $driverName = '';
            for ($i = 1; $i < count($a); $i++)
            {
                if ($i > 1) {
                    $driverName .= '_';
                }
                $driverName .= $a[$i];
            }
            $className = 'ImportDriver_' . $driverName;
            $drivers[$driverName] = new $className;
        }
        return $drivers;
    }

    /**
     * This function imports 10 rows of the CSV data
     * @return void
     */
    private function __ajaxImportRows()
    {
        $messageSuffix = '';
        $updated = array();
        $ignored = array();

        $csv = $this->__getCSV();
        if($csv != false)
        {
            // Load the drivers:
            $drivers = $this->getDrivers();

            // Default parameters:
            $currentRow = intval($_POST['row']);
            $sectionID = $_POST['section-id'];
            $uniqueAction = $_POST['unique-action'];
            $uniqueField = $_POST['unique-field'];
            $fieldIDs = explode(',', $_POST['field-ids']);
            $entryID = null;

            // Load the fieldmanager:
            $fm = new FieldManager($this);

            // Load the CSV data of the specific rows:
            $csvTitles = $csv->titles;
            $csvData = $csv->data;
            for ($i = $currentRow * 10; $i < ($currentRow + 1) * 10; $i++)
            {
                // Start by creating a new entry:
                $entry = new Entry($this);
                $entry->set('section_id', $sectionID);
                
                // Import this row:
                $row = $csvData[$i];
                if($row != false)
                {

                    // If a unique field is used, make sure there is a field selected for this:
                    if($uniqueField != 'no' && $fieldIDs[$uniqueField] == 0)
                    {
                        die(__('[ERROR: No field id sent for: "' . $csvTitles[$uniqueField] . '"]'));
                    }

                    // Unique action:
                    if($uniqueField != 'no')
                    {
                        // Check if there is an entry with this value:
                        // $entryID = $this->__scanDatabase($row[$csvTitles[$uniqueField]], $fieldIDs[$uniqueField]);
                        $field = $fm->fetch($fieldIDs[$uniqueField]);
                        $type = $field->get('type');
                        if (isset($drivers[$type])) {
                            $drivers[$type]->setField($field);
                            $entryID = $drivers[$type]->scanDatabase($row[$csvTitles[$uniqueField]]);
                        } else {
                            $drivers['default']->setField($field);
                            $entryID = $drivers['default']->scanDatabase($row[$csvTitles[$uniqueField]]);
                        }

                        if ($entryID != false) {
                            // Update? Ignore? Add new?
                            switch ($uniqueAction)
                            {
                                case 'update' :
                                    {
                                    $entry->set('id', $entryID);
                                    $updated[] = $entryID;
                                    break;
                                    }
                                case 'ignore' :
                                    {
                                    // die(__('[DUPLICATE: IGNORED]'));
                                    $ignored[] = $entryID;
                                    break;
                                    }
                            }
                        }
                    }

                    // Do the actual importing:
                    $j = 0;
                    foreach ($row as $value)
                    {
                        // When no unique field is found, treat it like a new entry
                        // Otherwise, stop processing to safe CPU power.
                        $fieldID = intval($fieldIDs[$j]);
                        $field = $fm->fetch($fieldID);
                        // Get the corresponding field-type:
                        $type = $field->get('type');
                        if (isset($drivers[$type])) {
                            $drivers[$type]->setField($field);
                            $data = $drivers[$type]->import($value, $entryID);
                        } else {
                            $drivers['default']->setField($field);
                            $data = $drivers['default']->import($value, $entryID);
                        }
                        // Set the data:
                        if ($data != false) {
                            $entry->setData($fieldID, $data);
                        }
                        $j++;
                    }

                    // Store the entry:
                    $entry->commit();

                }
            }
        } else {
            die(__('[ERROR: CSV Data not found!]'));
        }

        if(count($updated) > 0)
        {
            $messageSuffix .= ' '.__('(updated: ').implode(', ', $updated).')';
        }
        if(count($ignored) > 0)
        {
            $messageSuffix .= ' '.__('(ignored: ').implode(', ', $updated).')';
        }

        die('[OK]' . $messageSuffix);
    }

    /**
     * This function imports an induvidial entry
     */
    /*
    private function __ajaxImport()
    {
        // Load the drivers:
        $drivers = $this->getDrivers();

        // Load the fields:
        $sectionID = $_POST['section-id'];
        $uniqueAction = $_POST['unique-action'];
        $uniqueField = $_POST['unique-field'];
        $uniqueFound = false;
        $uniqueID = 0;
        $uniqueValue = 0;
        $messageSuffix = '';
        $entryID = null;

        // Load the fieldmanager:
        $fm = new FieldManager($this);

        // Start by creating a new entry:
        $entry = new Entry($this);
        $entry->set('section_id', $sectionID);

        // First check which field is the update-field:
        if ($_POST['unique-field'] != 'no') {
            $i = 0;
            foreach ($_POST as $key => $value)
            {
                if (substr($key, 0, 6) == 'field-' && !$uniqueFound) {
                    if ($uniqueField == $i && $uniqueFound == false) {
                        $a = explode('-', $key);
                        if (count($a) == 2) {
                            $fieldID = intval($a[1]);
                            // Check for an update:
                            $uniqueFound = true;
                            $uniqueID = $fieldID;
                            $uniqueValue = $value;
                        } else {
                            die(__('[ERROR: No field id sent for: "' . $value . '"]'));
                        }
                    }
                    $i++;
                }
            }
        }

        // When a unique value is found, see if there is an entry with the ID:
        if ($uniqueFound) {
            // See if there is an entry with this value:
            $entryID = $this->__scanDatabase($uniqueValue, $uniqueID);
            if ($entryID != false) {
                // Update? Ignore? Add new?
                switch ($uniqueAction)
                {
                    case 'update' :
                        {
                        $entry->set('id', $entryID);
                        $messageSuffix = ' updated entry: ' . $entryID;
                        break;
                        }
                    case 'ignore' :
                        {
                        die(__('[DUPLICATE: IGNORED]'));
                        break;
                        }
                }
            }
        }

        // Do the actual importing:
        // Get the post values:
        $i = 0;
        foreach ($_POST as $key => $value)
        {
            // When no unique field is found, treat it like a new entry
            // Otherwise, stop processing to safe CPU power.
            if (substr($key, 0, 6) == 'field-') {
                $a = explode('-', $key);
                if (count($a) == 2) {
                    $fieldID = intval($a[1]);
                    $field = $fm->fetch($fieldID);
                    // Get the corresponding field-type:
                    $type = $field->get('type');
                    if (isset($drivers[$type])) {
                        $drivers[$type]->setField($field);
                        $data = $drivers[$type]->import($value, $entryID);
                    } else {
                        $drivers['default']->setField($field);
                        $data = $drivers['default']->import($value, $entryID);
                    }
                    // Set the data:
                    if ($data != false) {
                        $entry->setData($fieldID, $data);
                    }
                } else {
                    die(__('[ERROR: No field id sent for: "' . $value . '"]'));
                }
                $i++;
            }
        }

        // Store the entry:
        $entry->commit();

        // When the script gets here, it means everything has worked out fine!
        die('[OK]' . $messageSuffix);
    }
    */

    private function __exportPage()
    {
        // Load the drivers:
        $drivers = $this->getDrivers();

        // Get the fields of this section:
        $sectionID = $_REQUEST['section-export'];
        $sm = new SectionManager($this);
        $em = new EntryManager($this);
        $section = $sm->fetch($sectionID);
        $fileName = $section->get('handle') . '_' . date('Y-m-d') . '.csv';
        $fields = $section->fetchFields();

        $headers = array();
        foreach ($fields as $field)
        {
            $headers[] = '"' . str_replace('"', '""', $field->get('label')) . '"';
        }

        header('Content-type: text/csv');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');

        // Show the headers:
        echo implode(',', $headers) . "\n";

        // Show the content:
        $entries = $em->fetch(null, $sectionID);
        foreach ($entries as $entry)
        {
            $line = array();
            foreach ($fields as $field)
            {
                $data = $entry->getData($field->get('id'));
                $type = $field->get('type');
                if (isset($drivers[$type])) {
                    $drivers[$type]->setField($field);
                    $value = $drivers[$type]->export($data, $entry->get('id'));
                } else {
                    $drivers['default']->setField($field);
                    $value = $drivers['default']->export($data, $entry->get('id'));
                }
                $line[] = '"' . str_replace('"', '""', $value) . '"';
            }
            echo implode(',', $line) . "\n";
        }
        die();
    }

}

