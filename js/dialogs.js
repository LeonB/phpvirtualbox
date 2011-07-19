/*
 * $Id$
 * Copyright (C) 2011 Ian Moore (imoore76 at yahoo dot com)
 */

/*
 * 
 * Import appliance wizard
 * 
 */
function vboxWizardImportApplianceInit() {

	var l = new vboxLoader();
	l.add('EnumNetworkAdapterType',function(d){$('#vboxIndex').data('vboxNetworkAdapterTypes',d);});
	l.add('EnumAudioControllerType',function(d){$('#vboxIndex').data('vboxAudioControllerTypes',d);});	
	l.onLoad = function() {

		var vbw = new vboxWizard('wizardImportAppliance',trans('Appliance Import Wizard','UIImportApplianceWzd'),'images/vbox/vmw_ovf_import.png', 'images/vbox/vmw_ovf_import_bg.png','import');
		vbw.steps = 2;
		vbw.height = 500;
		vbw.finishText = trans('Import','UIImportApplianceWzd');
		vbw.context = 'UIImportApplianceWzd';
		vbw.perPageContext = 'UIImportApplianceWzdPage%1';
		vbw.onFinish = function(wiz,dialog) {
		
			var file = $(document.forms['frmwizardImportAppliance'].elements.wizardImportApplianceLocation).val();
			var descriptions = $('#vboxImportProps').data('descriptions');
			var reinitNetwork = document.forms['frmwizardImportAppliance'].elements.vboxImportReinitNetwork.checked;
			
			// Step through each VM and obtain value
			for(var a = 0; a < descriptions.length; a++) {
				var children = $('#vboxImportProps').children('tr.vboxChildOf'+a);
				descriptions[a][5] = []; // enabled / disabled
				for(var b = 0; b < children.length; b++) {
					descriptions[a][5][b] = !$(children[b]).data('propdisabled');
					descriptions[a][3][$(children[b]).data('descOrder')] = $(children[b]).children('td:eq(1)').data('descValue');
				}
			}
			
			var l = new vboxLoader();
			l.mode = 'save';
			l.add('applianceImport',function(d){
				if(d && d.progress) {
					vboxProgress(d.progress,function(){
						$('#vboxIndex').trigger('vmlistreload');
						// Imported media must be refreshed
						var ml = new vboxLoader();
						ml.add('Media',function(d){$('#vboxIndex').data('vboxMedia',d);});
						ml.run();
					},{},'progress_import_90px.png',trans('Import Appliance','VBoxSelectorWnd').replace(/\./g,''));
				}
			},{'descriptions':descriptions,'file':file,'reinitNetwork':reinitNetwork});
			$(dialog).trigger('close').empty().remove();
			l.run();
	
	
		};
		vbw.run();
	};
	l.run();
}

/*
 * 
 * Export appliance wizard
 * 
 * 
 */
function vboxWizardExportApplianceInit() {

	var vbw = new vboxWizard('wizardExportAppliance',trans('Appliance Export Wizard','UIExportApplianceWzd'),'images/vbox/vmw_ovf_export.png','images/vbox/vmw_ovf_export_bg.png','export');
	vbw.steps = 4;
	vbw.height = 500;
	vbw.context = 'UIExportApplianceWzd';
	vbw.finishText = trans('Export','UIExportApplianceWzd');
	vbw.perPageContext = 'UIExportApplianceWzdPage%1';
	vbw.onFinish = function(wiz,dialog) {
		
		function vboxExportApp(force) {

			// Each VM
			var vmid = null;
			var vms = {};
			var vmsAndProps = $('#vboxExportProps').children('tr');
			for(var a = 0; a < vmsAndProps.length; a++) {
				if($(vmsAndProps[a]).hasClass('vboxTableParent')) {
					vmid = $(vmsAndProps[a]).data('vm').id
					vms[vmid] = {};
					vms[vmid]['id'] = vmid;
					continue;
				}
				
				var prop = $(vmsAndProps[a]).data('vmprop');
				vms[vmid][prop] = $(vmsAndProps[a]).children('td:eq(1)').children().first().text();
					
			}

			var file = $(document.forms['frmwizardExportAppliance'].elements.wizardExportApplianceLocation).val();
			var format = (document.forms['frmwizardExportAppliance'].elements.wizardExportApplianceLegacy.checked ? 'ovf-0.9' : '');
			var manifest = (document.forms['frmwizardExportAppliance'].elements.wizardExportApplianceManifest.checked ? 1 : 0);
			var overwrite = force;
			
			var l = new vboxLoader();
			l.mode = 'save';
			l.add('applianceExport',function(d){
				if(d && d.progress)
					vboxProgress(d.progress,function(){return;},{},'progress_export_90px.png',trans('Export Appliance...','VBoxSelectorWnd').replace(/\./g,''));
			},{'format':format,'file':file,'vms':vms,'manifest':manifest,'overwrite':overwrite});
			$(dialog).trigger('close').empty().remove();
			l.run();
			
		}

		/* Check to see if file exists */
		var loc = $(document.forms['frmwizardExportAppliance'].elements.wizardExportApplianceLocation).val();
		var fileExists = false;
		var fe = new vboxLoader();
		fe.mode='save';
		fe.add('fileExists',function(d){
			fileExists = d.exists;
		},{'file':loc});
		fe.onLoad = function() { 
			if(fileExists) {
				var buttons = {};
				buttons[trans('Yes','QIMessageBox')] = function() {
					vboxExportApp(1);
				}
				vboxConfirm(trans('A file named <b>%1</b> already exists. Are you sure you want to replace it?<br /><br />Replacing it will overwrite its contents.','VBoxProblemReporter').replace('%1',vboxBasename(loc)),buttons,trans('No','QIMessageBox'));
				return;
			}
			vboxExportApp();
			
		}
		fe.run();



	};
	vbw.run();

}

/*
 * 
 * Port forwarding configuration dialog
 * 
 */
function vboxPortForwardConfigInit(rules,callback) {
	var l = new vboxLoader();
	l.addFile("panes/settingsPortForwarding.html",function(f){$('#vboxIndex').append(f);});
	l.onLoad = function(){
		vboxSettingsPortForwardingInit(rules);
		var buttons = {};
		buttons[trans('OK','QIMessageBox')] = function(){
			// Get rules
			var rules = $('#vboxSettingsPortForwardingList').children('tr');
			var rulesToPass = new Array();
			for(var i = 0; i < rules.length; i++) {
				if($(rules[i]).data('vboxRule')[3] == 0 || $(rules[i]).data('vboxRule')[5] == 0) {
					vboxAlert(trans('The current port forwarding rules are not valid. None of the host or guest port values may be set to zero.','VBoxProblemReporter'));
					return;
				}
				rulesToPass[i] = $(rules[i]).data('vboxRule');
			}
			callback(rulesToPass);
			$(this).trigger('close').empty().remove();
		};
		buttons[trans('Cancel','QIMessageBox')] = function(){$(this).trigger('close').empty().remove();};
		$('#vboxSettingsPortForwarding').dialog({'closeOnEscape':true,'width':600,'height':400,'buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent','title':'<img src="images/vbox/nw_16px.png" class="vboxDialogTitleIcon" /> ' + trans('Port Forwarding Rules','UIMachineSettingsPortForwardingDlg')}).bind("dialogbeforeclose",function(){
	    	$(this).parent().find('span:contains("'+trans('Cancel','QIMessageBox')+'")').trigger('click');
	    });
	}
	l.run();
}

/*
 * 
 * 
 * New Virtual Machine Wizard
 * 
 * 
 * 
 */
function vboxWizardNewVMInit(callback) {

	var l = new vboxLoader();
	l.add('Media',function(d){$('#vboxIndex').data('vboxMedia',d);});
	
	l.onLoad = function() {

		var vbw = new vboxWizard('wizardNewVM',trans('Create New Virtual Machine','UINewVMWzd'),'images/vbox/vmw_new_welcome.png','images/vbox/vmw_new_welcome_bg.png','new');
		vbw.steps = 5;
		vbw.context = 'UINewHDWizard';
		vbw.perPageContext = 'UINewVMWzdPage%1';
		vbw.finishText = trans('Finish','UINewVMWzd');
		vbw.onFinish = function(wiz,dialog) {

			// Get parameters
			var disk = document.forms['frmwizardNewVM'].newVMDiskSelect.options[document.forms['frmwizardNewVM'].newVMDiskSelect.selectedIndex].value;
			var name = jQuery.trim(document.forms['frmwizardNewVM'].newVMName.value);
			var ostype = document.forms['frmwizardNewVM'].newVMOSType.options[document.forms['frmwizardNewVM'].newVMOSType.selectedIndex].value;
			var mem = parseInt(document.forms['frmwizardNewVM'].wizardNewVMSizeValue.value);
			if(!document.forms['frmwizardNewVM'].newVMBootDisk.checked) disk = null;

			vboxAjaxRequest('createVM',{'disk':disk,'ostype':ostype,'memory':mem,'name':name},function(res){
				if(res && res.result) {
					$(dialog).trigger('close').empty().remove();
					var lm = new vboxLoader();
					lm.add('Media',function(d){$('#vboxIndex').data('vboxMedia',d);});
					lm.onLoad = function(){
						$('#vboxIndex').trigger('vmlistreload');
						if(callback) callback();
					}
					lm.run();
				}
			});			

		};
		vbw.run();
	}
	l.run();
	
}

/*
 * 
 * 
 * Clone Virtual Machine Wizard
 * 
 * 
 * 
 */
function vboxWizardCloneVMInit(callback,args) {
	
	var l = new vboxLoader();
	l.add('VMDetails',function(d){
		args.vm = d;
	},{'vm':args.vm.id});
	l.onLoad = function() {
		
		var vbw = new vboxWizard('wizardCloneVM',trans('Clone a virtual machine','UICloneVMWizard'),'images/vbox/vmw_clone.png','images/vbox/vmw_clone_bg.png','vm_clone');
		vbw.steps = (args.vm.snapshotCount > 0 ? 2 : 1);
		vbw.args = args;
		vbw.finishText = trans('Clone','UICloneVMWizard');
		vbw.context = 'UICloneVMWizard';
		vbw.perPageContext = 'UICloneVMWizardPage%1';
		
		vbw.onFinish = function(wiz,dialog) {
	
			// Get parameters
			var name = document.forms['frmwizardCloneVM'].elements.cloneVMName.value;
			var vmState = $(document.forms['frmwizardCloneVM'].elements.vmState).val();
			var src = vbw.args.vm.id;
			var snapshot = vbw.args.snapshot;
			var allNetcards = document.forms['frmwizardCloneVM'].elements.vboxCloneReinitNetwork.checked;
	
			var l = new vboxLoader();
			l.mode = 'save';
			l.add('cloneVM',function(d,e){
				var registerVM = null;
				if(d && d.settingsFilePath) {
					registerVM = d.settingsFilePath;
				}
				if(d && d.progress) {
					vboxProgress(d.progress,function(ret) {
						vboxAjaxRequest('addVM',{'file':registerVM},function(){
							var ml = new vboxLoader();
							ml.add('Media',function(dat){$('#vboxIndex').data('vboxMedia',dat);});
							ml.onLoad = function() {
								$('#vboxIndex').trigger('vmlistreload');
								callback();
							};
							ml.run();
						});
					},d.id,'progress_clone_90px.png',trans('Clone a virtual machine','UICloneVMWizard'));
				} else {
					callback();
				}
			},{'name':name,'vmState':vmState,'src':src,'snapshot':snapshot,'reinitNetwork':allNetcards});
			l.run();
			
			$(dialog).trigger('close').empty().remove();
	
		};
		vbw.run();
	}
	l.run();
}

/*
 * 
 * 
 * Show vm logs
 * 
 * 
 */
function vboxShowLogsDialogInit(vm) {

	$('#vboxIndex').append($('<div />').attr({'id':'vboxVMLogsDialog'}));
	
	var l = new vboxLoader();
	l.add('VMLogFilesInfo',function(r){
		$('#vboxVMLogsDialog').data({'logs':r.logs,'logpath':r.path});
	},{'vm':vm});
	l.addFile('panes/vmlogs.html',function(f){$('#vboxVMLogsDialog').append(f);});
	l.onLoad = function(){
		var buttons = {};
		vboxSetLangContext('VBoxVMLogViewer');
		buttons[trans('Refresh','VBoxVMLogViewer')] = function() {
			l = new vboxLoader();
			l.add('VMLogFilesInfo',function(r){
				$('#vboxVMLogsDialog').data({'logs':r.logs,'logpath':r.path});
				
			},{'vm':vm});
			l.onLoad = function(){
				vboxShowLogsInit(vm);
			}
			l.run();
		};
		buttons[trans('Close','VBoxVMLogViewer')] = function(){$(this).trigger('close').empty().remove();};
		$('#vboxVMLogsDialog').dialog({'closeOnEscape':true,'width':800,'height':500,'buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent','title':'<img src="images/vbox/show_logs_16px.png" class="vboxDialogTitleIcon" /> '+ trans('%1 - VirtualBox Log Viewer').replace('%1',$('#vboxIndex').data('selectedVM').name)}).bind("dialogbeforeclose",function(){
	    	$(this).parent().find('span:contains("'+trans('Close','VBoxVMLogViewer')+'")').trigger('click');
	    });
		vboxShowLogsInit(vm);
		vboxUnsetLangContext();
	};
	l.run();

}

/*
 * 
 * 	Virtual Media Manager Dialog
 * 
 * 
 */

function vboxVMMDialogInit(callback,type,hideDiff,attached,vmPath) {

	$('#vboxIndex').append($('<div />').attr({'id':'vboxVMMDialog'}));
			
	var l = new vboxLoader();
	l.add('Config',function(d){$('#vboxIndex').data('vboxConfig',d);});
	l.add('SystemProperties',function(d){$('#vboxIndex').data('vboxSystemProperties',d);});
	l.add('Media',function(d){$('#vboxIndex').data('vboxMedia',d);});
	l.addFile('panes/vmm.html',function(f){$('#vboxVMMDialog').append(f);});
	l.onLoad = function() {
		var buttons = {};
		if(callback) {
			buttons[trans('OK','QIMessageBox')] = function() {
				var sel = null;
				switch($("#vboxVMMTabs").tabs('option','selected')) {
					case 0: /* HardDisks */
						sel = $('#vboxVMMHDList').find('tr.vboxListItemSelected').first();
						break;
					case 1: /* DVD */
						sel = $('#vboxVMMCDList').find('tr.vboxListItemSelected').first();
						break;
					default:
						sel = $('#vboxVMMFDList').find('tr.vboxListItemSelected').first();
				}
				if($(sel).length) {
					callback($(sel).data('medium'));
				}
				$('#vboxVMMDialog').trigger('close').empty().remove();
			}
		}
		buttons[trans('Close','QIMessageBox')] = function() {
			$('#vboxVMMDialog').trigger('close').empty().remove();
			if(callback) callback(null);
		};

		vboxSetLangContext('VBoxMediaManagerDlg');
		
		$("#vboxVMMDialog").dialog({'closeOnEscape':true,'width':800,'height':500,'buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent','title':'<img src="images/vbox/diskimage_16px.png" class="vboxDialogTitleIcon" /> '+trans('Virtual Media Manager')}).bind("dialogbeforeclose",function(){
	    	$(this).parent().find('span:contains("'+trans('Close','QIMessageBox')+'")').trigger('click');
	    });
		
		vboxVMMInit(hideDiff,attached,vmPath);
		
		vboxUnsetLangContext();
		
		if(type) {
			switch(type) {
				case 'HardDisk':
					$("#vboxVMMTabs").tabs('select',0);
					$("#vboxVMMTabs").tabs('disable',1);
					$("#vboxVMMTabs").tabs('disable',2);					
					break;
				case 'DVD':
					$("#vboxVMMTabs").tabs('select',1);
					$("#vboxVMMTabs").tabs('disable',0);
					$("#vboxVMMTabs").tabs('disable',2);					
					break;
				case 'Floppy':
					$("#vboxVMMTabs").tabs('select',2);
					$("#vboxVMMTabs").tabs('disable',0);
					$("#vboxVMMTabs").tabs('disable',1);
					break;
				default:
					$("#vboxVMMTabs").tabs('select',0);
					break;
			}
		}
	}
	l.run();
}

/*
 * 
 * 	New Virtual Disk wizard dialog
 * 
 * 
 */
function vboxWizardNewHDInit(callback,suggested) {

	var l = new vboxLoader();
	l.add('SystemProperties',function(d){$('#vboxIndex').data('vboxSystemProperties',d);});
	l.add('Media',function(d){$('#vboxIndex').data('vboxMedia',d);});
	
	// Compose folder if suggested name exists
	if(suggested && suggested.name) {
		l.add('ComposedMachineFilename',function(d){suggested.path = vboxDirname(d.file)+$('#vboxIndex').data('vboxConfig').DSEP},{'name':suggested.name})
	}
	l.onLoad = function() {
		
		vboxSetLangContext('UINewHDWizard');
		
		var vbw = new vboxWizard('wizardNewHD',trans('Create New Virtual Disk'),'images/vbox/vmw_new_harddisk.png','images/vbox/vmw_new_harddisk_bg.png','hd');
		vbw.steps = 4;
		vbw.suggested = suggested;
		vbw.context = 'UINewHDWizard';
		vbw.finishText = trans('Create','UINewHDWizard');
		
		vbw.onFinish = function(wiz,dialog) {

			var file = document.forms['frmwizardNewHD'].elements.wizardNewHDLocation.value;
			var size = vboxConvertMbytes(document.forms['frmwizardNewHD'].elements.wizardNewHDSizeValue.value);
			var type = (document.forms['frmwizardNewHD'].elements.newHardDiskType[1].checked ? 'fixed' : 'dynamic');

			$(dialog).trigger('close').empty().remove();

			var l = new vboxLoader();
			l.mode = 'save';
			l.add('mediumCreateBaseStorage',function(d,e){
				if(d && d.progress) {
					vboxProgress(d.progress,function(ret,mid) {
							var ml = new vboxLoader();
							ml.add('Media',function(dat){$('#vboxIndex').data('vboxMedia',dat);});
							ml.onLoad = function() {
								med = vboxMedia.getMediumById(mid);
								vboxMedia.updateRecent(med);
								callback(mid);
							}
							ml.run();
					},d.id,'progress_media_create_90px.png',trans('Create New Virtual Disk','UINewHDWizard'));
				} else {
					callback({},d.id);
				}
			},{'file':file,'type':type,'size':size});
			l.run();
			
		};
		vbw.run();
		
		vboxUnsetLangContext();
		
	}
	l.run();
	
}

/*
 * 
 * 	Copy Virtual Disk wizard dialog
 * 
 * 
 */
function vboxWizardCopyHDInit(callback,suggested) {

	var l = new vboxLoader();
	l.add('SystemProperties',function(d){$('#vboxIndex').data('vboxSystemProperties',d);});
	l.add('Media',function(d){$('#vboxIndex').data('vboxMedia',d);});
	
	l.onLoad = function() {
		
		vboxSetLangContext('UINewHDWizard');
		
		var vbw = new vboxWizard('wizardCopyHD',trans('Copy Virtual Disk'),'images/vbox/vmw_new_harddisk.png','images/vbox/vmw_new_harddisk_bg.png','hd');
		vbw.steps = 5;
		vbw.suggested = suggested;
		vbw.context = 'UINewHDWizard';
		vbw.finishText = trans('Copy','UINewHDWizard');
		vbw.onFinish = function(wiz,dialog) {


			var src = $(document.forms['frmwizardCopyHD'].copyHDDiskSelect).val();
			var type = (document.forms['frmwizardCopyHD'].elements.newHardDiskType[1].checked ? 'fixed' : 'dynamic');
			var format = document.forms['frmwizardCopyHD'].elements['copyHDFileType'];
			for(var i = 0; i < format.length; i++) {
				if(format[i].checked) {
					format=format[i].value;
					break;
				}
			}
			var location = $('#wizardCopyHDLocationLabel').text();
			
			$(dialog).trigger('close').empty().remove();

			var l = new vboxLoader();
			l.mode = 'save';
			l.add('mediumCloneTo',function(d,e){
				if(d && d.progress) {
					vboxProgress(d.progress,function(ret,mid) {
							var ml = new vboxLoader();
							ml.add('Media',function(dat){$('#vboxIndex').data('vboxMedia',dat);});
							ml.onLoad = function() {
								med = vboxMedia.getMediumById(mid);
								vboxMedia.updateRecent(med);
								callback(mid);
							}
							ml.run();
					},d.id,'progress_media_create_90px.png',trans('Copy Virtual Disk','UINewHDWizard'));
				} else {
					callback(d.id);
				}
			},{'src':src,'type':type,'format':format,'location':location});
			l.run();
			
		};
		vbw.run();
		vboxUnsetLangContext();
	}
	l.run();
	
}

/*
 * 
 * Initialize guest network dialog
 * 
 */
function vboxGuestNetworkAdaptersDialogInit(vm,nic) {

	/*
	 * 	Dialog
	 */
	$('#vboxIndex').append($('<div />').attr({'id':'vboxGuestNetworkDialog','style':'display: none'}));

	/*
	 * Loader
	 */
	var l = new vboxLoader();
	l.addFile('panes/guestNetAdapters.html',function(f){$('#vboxGuestNetworkDialog').append(f);})
	l.onLoad = function(){
		
		var buttons = {};
		buttons[trans('Close','VBoxVMInformationDlg')] = function() {$('#vboxGuestNetworkDialog').trigger('close').empty().remove();};
		$('#vboxGuestNetworkDialog').dialog({'closeOnEscape':true,'width':500,'height':250,'buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent','title':'<img src="images/vbox/nw_16px.png" class="vboxDialogTitleIcon" /> ' + trans('Guest Network Adapters','VBoxGlobal')}).bind("dialogbeforeclose",function(){
	    	$(this).parent().find('span:contains("'+trans('Close','VBoxVMInformationDlg')+'")').trigger('click');
	    });
		
		// defined in pane
		vboxVMNetAdaptersInit(vm,nic);
	}
	l.run();
	
}


/*
 * 
 * Initialize a Preferences dialog
 * 
 */

function vboxPrefsInit() {
	
	// Prefs
	var panes = new Array(
		{'name':'GlobalGeneral','label':'General','icon':'machine','context':'UIGlobalSettingsGeneral'},
		{'name':'GlobalNetwork','label':'Network','icon':'nw','context':'UIGlobalSettingsNetwork'},
		{'name':'GlobalLanguage','label':'Language','icon':'site','context':'UIGlobalSettingsLanguage'},
		{'name':'GlobalUsers','label':'Users','icon':'register','context':'UIUsers'}
	);
	
	var data = new Array(
		{'fn':'HostOnlyNetworking','callback':function(d){$('#vboxSettingsDialog').data('vboxHostOnlyNetworking',d);}},
		{'fn':'SystemProperties','callback':function(d){$('#vboxSettingsDialog').data('vboxSystemProperties',d);}},
		{'fn':'Users','callback':function(d){$('#vboxSettingsDialog').data('vboxUsers',d);}}
	);	
	
	// Check for noAuth setting
	if($('#vboxIndex').data('vboxConfig').noAuth || !$('#vboxIndex').data('vboxSession').admin || !$('#vboxIndex').data('vboxConfig').authCapabilities.canModifyUsers) {
		panes.pop();
		data.pop();
	}
	
	vboxSettingsInit(trans('Preferences...','VBoxSelectorWnd').replace(/\./g,''),panes,data,function(){
		var l = new vboxLoader();
		l.mode = 'save';
		
		// Language change?
		if($('#vboxSettingsDialog').data('language') && $('#vboxSettingsDialog').data('language') != __vboxLangName) {
			vboxSetCookie('vboxLanguage',$('#vboxSettingsDialog').data('language'));
			l.onLoad = function(){location.reload(true);}
			
		// Update host info in case interfaces were added / removed
		} else if($('#vboxIndex').data('selectedVM') && $('#vboxIndex').data('selectedVM')['id'] == 'host') {
			l.onLoad = function() {
				$('#vboxIndex').trigger('vmselect',[$('#vboxIndex').data('selectedVM')]);
			}
		}
		l.add('saveHostOnlyInterfaces',function(){},{'networkInterfaces':$('#vboxSettingsDialog').data('vboxHostOnlyNetworking').networkInterfaces});
		l.add('saveSystemProperties',function(){},{'SystemProperties':$('#vboxSettingsDialog').data('vboxSystemProperties')});
		l.run();
	},null,'global_settings','UISettingsDialogGlobal');
}



/*
 * 
 * Initialize a virtual machine settings dialog
 * 
 */

function vboxVMsettingsInit(vm,callback,pane) {
	
	var panes = new Array(
	
		{'name':'General','label':'General','icon':'machine','tabbed':true,'context':'UIMachineSettingsGeneral'},
		{'name':'System','label':'System','icon':'chipset','tabbed':true,'context':'UIMachineSettingsSystem'},
		{'name':'Display','label':'Display','icon':'vrdp','tabbed':true,'context':'UIMachineSettingsDisplay'},
		{'name':'Storage','label':'Storage','icon':'attachment','context':'UIMachineSettingsStorage'},
		{'name':'Audio','label':'Audio','icon':'sound','context':'UIMachineSettingsAudio'},
		{'name':'Network','label':'Network','icon':'nw','tabbed':true,'context':'UIMachineSettingsNetwork'},
		{'name':'SerialPorts','label':'Serial Ports','icon':'serial_port','tabbed':true,'context':'UIMachineSettingsSerial'},
		{'name':'ParallelPorts','label':'Parallel Ports','icon':'parallel_port','tabbed':true,'disabled':(!$('#vboxIndex').data('vboxConfig').enableLPTConfig),'context':'UIMachineSettingsParallel'},
		{'name':'USB','label':'USB','icon':'usb','context':'UIMachineSettingsUSB'},
		{'name':'SharedFolders','label':'Shared Folders','icon':'shared_folder','context':'UIMachineSettingsSF'}
			
	);
	
	var data = new Array(
		{'fn':'Media','callback':function(d){$('#vboxIndex').data('vboxMedia',d);}},
		{'fn':'HostNetworking','callback':function(d){$('#vboxSettingsDialog').data('vboxHostNetworking',d);}},
		{'fn':'HostDetails','callback':function(d){$('#vboxSettingsDialog').data('vboxHostDetails',d);}},
		{'fn':'VMDetails','callback':function(d){$('#vboxSettingsDialog').data('vboxMachineData',d);},'args':{'vm':vm,'force_refresh':$('#vboxIndex').data('vboxConfig').vmConfigRefresh}},
		{'fn':'EnumNetworkAdapterType','callback':function(d){$('#vboxSettingsDialog').data('vboxNetworkAdapterTypes',d);}},
		{'fn':'EnumAudioControllerType','callback':function(d){$('#vboxSettingsDialog').data('vboxAudioControllerTypes',d);}},
		{'fn':'RecentMedia','callback':function(d){$('#vboxIndex').data('vboxRecentMedia',d);}}

	);

	vboxSetLangContext('UISettingsDialogMachine');
	vboxSettingsInit($('#vboxIndex').data('selectedVM').name + ' - ' + trans('Settings','UISettingsDialog'),panes,data,function(){
		var loader = new vboxLoader();
		loader.mode = 'save';
		var sdata = $.extend($('#vboxSettingsDialog').data('vboxMachineData'),{'enableAdvancedConfig':$('#vboxIndex').data('vboxConfig').enableAdvancedConfig});
		loader.add('saveVM',function(){return;},sdata);
		loader.onLoad = function() {
			// Refresh media
			var mload = new vboxLoader();
			mload.add('Media',function(d){$('#vboxIndex').data('vboxMedia',d);});
			mload.onLoad = function() {
				if(callback){callback();}
			}
			mload.run();
		}
		loader.run();
	},pane,'settings','UISettingsDialogMachine');
	vboxUnsetLangContext();
}

/*
 * Network settings dialog for VM when VM is running 
 */
function vboxVMsettingsInitNetwork(vm,callback) {
	
	var panes = new Array(
		{'name':'Network','label':'Network','icon':'nw','tabbed':true,'context':'UIMachineSettingsNetwork'}
	);
	
	var data = new Array(
			{'fn':'HostNetworking','callback':function(d){$('#vboxSettingsDialog').data('vboxHostNetworking',d);}},
			{'fn':'HostDetails','callback':function(d){$('#vboxSettingsDialog').data('vboxHostDetails',d);}},
			{'fn':'VMDetails','callback':function(d){$('#vboxSettingsDialog').data('vboxMachineData',d);},'args':{'vm':vm}},
			{'fn':'EnumNetworkAdapterType','callback':function(d){$('#vboxSettingsDialog').data('vboxNetworkAdapterTypes',d);}},
			{'fn':'EnumAudioControllerType','callback':function(d){$('#vboxSettingsDialog').data('vboxAudioControllerTypes',d);}}

	);

	vboxSettingsInit(trans('Settings'),panes,data,function(){
		var loader = new vboxLoader();
		loader.mode = 'save';
		var sdata = $.extend($('#vboxSettingsDialog').data('vboxMachineData'),{'enableAdvancedConfig':$('#vboxIndex').data('vboxConfig').enableAdvancedConfig});
		loader.add('saveVMNetwork',function(){if(callback){callback();}},sdata);
		loader.run();
	},'Network','nw');
}

/*
 * SharedFolders settings dialog for VM when VM is running 
 */
function vboxVMsettingsInitSharedFolders(vm,callback) {
	
	var panes = new Array(
		{'name':'SharedFolders','label':'Shared Folders','icon':'shared_folder','tabbed':false,'context':'UIMachineSettingsSF'}
	);
	
	var data = new Array(
			{'fn':'HostDetails','callback':function(d){$('#vboxSettingsDialog').data('vboxHostDetails',d);}},
			{'fn':'VMDetails','callback':function(d){$('#vboxSettingsDialog').data('vboxMachineData',d);},'args':{'vm':vm}},
			{'fn':'VMTransientSharedFolders','callback':function(d){$('#vboxSettingsDialog').data('vboxTransientSharedFolders',d);},'args':{'vm':vm}}
	);

	vboxSettingsInit(trans('Settings'),panes,data,function(){
		var loader = new vboxLoader();
		loader.mode = 'save';
		loader.add('saveVMSharedFolders',function(){if(callback){callback();}},$('#vboxSettingsDialog').data('vboxMachineData'));
		loader.run();
	},'SharedFolders','shared_folder');
}



/*
 * 
 * 	Initialize a settings dialog (generic)
 * 		called by other dialog initializers
 * 
 * 
 */
function vboxSettingsInit(title,panes,data,onsave,pane,icon,langContext) {
	
	var d = $('<div />').attr({'id':'vboxSettingsDialog','style':'display: none;'});
	
	var f = $('<form />').attr({'name':'frmVboxSettings','style':'height: 100%'});
	
	var t = $('<table />').attr({'style':'height: 100%;','class':'vboxSettingsTable'});
	
	var tr = $('<tr />');
	
	$($('<td />').attr({'id':'vboxSettingsMenu','style': (panes.length == 1 ? 'display:none;' : '')})).append($('<ul />').attr({'id':'vboxSettingsMenuList','class':'vboxHover'})).appendTo(tr);
	
	var td = $('<td />').attr({'id':'vboxSettingsPane'}).css({'height':'100%'});
	
	// Settings table contains title and visible settings pane
	var stbl = $('<table />').css({'height':'100%','width':'100%','padding':'0px','margin':'0px','border':'0px','border-spacing':'0px'})
	
	// Title
	var d1 = $('<div />').attr({'id':'vboxSettingsTitle'}).html('Padding').css({'display':(panes.length == 1 ? 'none' : '')});
	$(stbl).append($('<tr />').append($('<td />').css({'height':'1%','padding':'0px','margin':'0px','border':'0px'}).append(d1)));
	
	
	// Settings pane
	var d1 = $('<div />').attr({'id':'vboxSettingsList'}).css({'width':'100%'});
	
	$(stbl).append($('<tr />').append($('<td />').css({'padding':'0px','margin':'0px','border':'0px'}).append(d1)));
	
	
	$(td).append(stbl).appendTo(tr);
	
	$(d).append($(f).append($(t).append(tr))).appendTo('#vboxIndex');
	
	/* Load panes and data */
	var loader = new vboxLoader();
	
	/* Load Data */
	for(var i = 0; i < data.length; i++) {
		loader.add(data[i].fn,data[i].callback,(data[i].args ? data[i].args : undefined));
	}

	/* Load settings panes */
	for(var i = 0; i < panes.length; i++) {
		
		if(panes[i].disabled) continue;
		
		// Menu item
		$('<li />').html('<div><img src="images/vbox/'+panes[i].icon+'_16px.png" /></div> <div>'+trans(panes[i].label,langContext)+'</div>').data(panes[i]).click(function(){
			
			$('#vboxSettingsTitle').html(trans($(this).data('label'),langContext));
			
			$(this).addClass('vboxListItemSelected').siblings().addClass('vboxListItem').removeClass('vboxListItemSelected');
			
			// jquery apply this css to everything with class .settingsPa..
			$('#vboxSettingsDialog .vboxSettingsPaneSection').css({'display':'none'});
			
			// Show selected pane
			$('#vboxSettingsPane-' + $(this).data('name')).css({'display':''}).children().first().trigger('show');
			
			// Opera hidden select box bug
			////////////////////////////////
			if($.browser.opera) {
				$('#vboxSettingsPane-' + $(this).data('name')).find('select').trigger('show');
			}

		}).hover(function(){$(this).addClass('vboxHover');},function(){$(this).removeClass('vboxHover');}).appendTo($('#vboxSettingsMenuList'));
		
		
		// Settings pane
		$('#vboxSettingsList').append($('<div />').attr({'id':'vboxSettingsPane-'+panes[i].name,'style':'display: none;','class':'vboxSettingsPaneSection ui-corner-all ' + (panes[i].tabbed ? 'vboxTabbed' : 'vboxNonTabbed')}));
		
		loader.addFile('panes/settings'+panes[i].name+'.html',function(f,i){
			$('#vboxSettingsPane-'+i.setting).append(f);
		},{'setting':panes[i].name});
		
	}

	loader.onLoad = function(){
		
		/* Init UI Items */
		for(var i = 0; i < panes.length; i++) {
			vboxSetLangContext(panes[i].context);
			vboxInitDisplay($('#vboxSettingsPane-'+panes[i].name));
			vboxUnsetLangContext();
			if(panes[i].tabbed) $('#vboxSettingsPane-'+panes[i].name).tabs();
		}
		
		// Opera hidden select box bug
		////////////////////////////////
		if($.browser.opera) {
			$('#vboxSettingsPane').find('select').bind('change',function(){
				$(this).data('vboxSelected',$(this).val());
			}).bind('show',function(){
				$(this).val($(this).data('vboxSelected'));
			}).each(function(){
				$(this).data('vboxSelected',$(this).val());
			});
		}

		var buttons = { };
		buttons[trans('OK','QIMessageBox')] = function() {
			
			// Opera hidden select bug
			if($.browser.opera) {
				$('#vboxSettingsPane').find('select').each(function(){
					$(this).val($(this).data('vboxSelected'));
				});
			}
			
			$(this).trigger('save');
			onsave($(this));
			$(this).trigger('close').empty().remove();
			$(document).trigger('click');
		};
		buttons[trans('Cancel','QIMessageBox')] = function() {
			$('#vboxSettingsDialog').trigger('close').empty().remove();
			$(document).trigger('click');
		};

		
		// Show dialog
	    $('#vboxSettingsDialog').dialog({'closeOnEscape':true,'width':(panes.length > 1 ? 900 : 600),'height':(panes.length > 1 ? 500 : 450),'buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxSettingsDialog vboxDialogContent','title':(icon ? '<img src="images/vbox/'+icon+'_16px.png" class="vboxDialogTitleIcon" /> ' : '') + title}).bind("dialogbeforeclose",function(){
	    	$(this).parent().find('span:contains("'+trans('Cancel','QIMessageBox')+'")').trigger('click');
	    });

	    // Resize pane
	    $('#vboxSettingsList').height($('#vboxSettingsList').parent().innerHeight()-8).css({'overflow':'auto','padding':'0px','margin-top':'8px','border':'0px','border-spacing':'0px'});
	    
	    // Resizing dialog, resizes this too
	    $('#vboxSettingsDialog').bind('dialogresizestop',function(){
	    	var h = $('#vboxSettingsList').css({'display':'none'}).parent().innerHeight()
	    	$('#vboxSettingsList').height(h-8).css({'display':''});	    	
	    })
	    
	    /* Select first or passed menu item */
	    var i = 0;
	    var offset = 0;
	    var tab = undefined;
	    if(typeof pane == "string") {
	    	var section = pane.split(':');
	    	if(section[1]) tab = section[1];
	    	for(i = 0; i < panes.length; i++) {
	    		if(panes[i].disabled) offset++;
	    		if(panes[i].name == section[0]) break;
	    	}
	    }
	    i-=offset;
	    if(i >= panes.length) i = 0;
	    $('#vboxSettingsMenuList').children('li:eq('+i+')').first().click().each(function(){
	    	if(tab !== undefined) {
	    		$('#vboxSettingsPane-'+$(this).data('name')).tabs('select', parseInt(tab));
	    	}
	    	
	    });
	    
	    /* Only 1 pane? */
	    if(panes.length == 1) {
	    	$('#vboxSettingsDialog table.vboxSettingsTable').css('width','100%');
	    	$('#vboxSettingsDialog').dialog('option','title',(icon ? '<img src="images/vbox/'+icon+'_16px.png" class="vboxDialogTitleIcon" /> ' : '') + title + ' :: ' + trans(panes[0].label,langContext));
	    }
	    
		
	};
	
	loader.run();

}


