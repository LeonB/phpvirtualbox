/**
 * $Id$
 * Copyright (C) 2011 Ian Moore (imoore76 at yahoo dot com)
 */

/**
 * Run the import appliance wizard
 */
function vboxWizardImportApplianceInit() {

	var l = new vboxLoader();
	l.add('getEnumNetworkAdapterType',function(d){$('#vboxIndex').data('vboxNetworkAdapterTypes',d);});
	l.add('getEnumAudioControllerType',function(d){$('#vboxIndex').data('vboxAudioControllerTypes',d);});	
	l.onLoad = function() {

		var vbw = new vboxWizard('wizardImportAppliance',trans('Appliance Import Wizard','UIImportApplianceWzd'),'images/vbox/vmw_ovf_import.png', 'images/vbox/vmw_ovf_import_bg.png','import');
		vbw.steps = 2;
		vbw.height = 500;
		vbw.finishText = trans('Import','UIImportApplianceWzd');
		vbw.context = 'UIImportApplianceWzd';
		vbw.perPageContext = 'UIImportApplianceWzdPage%1';
		vbw.stepButtons = [
		   {
			   'name' : trans('Restore Defaults','UIImportApplianceWzd'),
			   'steps' : [2],
			   'click' : function() {
				   wizardImportApplianceParsed();
			   }
		   }
		];
		vbw.onFinish = function(wiz,dialog) {
		
			var file = $(document.forms['frmwizardImportAppliance'].elements.wizardImportApplianceLocation).val();
			var descriptions = $('#vboxImportProps').data('descriptions');
			var reinitNetwork = document.forms['frmwizardImportAppliance'].elements.vboxImportReinitNetwork.checked;
			
			// Import function
			var vboxImportApp = function() {
				
				$(dialog).trigger('close').empty().remove();
				
				var l = new vboxLoader();
				l.add('applianceImport',function(d){
					if(d && d.progress) {
						vboxProgress(d.progress,function(){
							$('#vboxIndex').trigger('vmlistreload');
							// Imported media must be refreshed
							var ml = new vboxLoader();
							ml.add('getMedia',function(d){$('#vboxIndex').data('vboxMedia',d);});
							ml.run();
						},{},'progress_import_90px.png',trans('Import Appliance','VBoxSelectorWnd').replace(/\./g,''));
					}
				},{'descriptions':descriptions,'file':file,'reinitNetwork':reinitNetwork});
				l.run();				
			};
			
			// license agreements
			var licenses = [];
			
			// Step through each VM and obtain value
			for(var a = 0; a < descriptions.length; a++) {
				var children = $('#vboxImportProps').children('tr.vboxChildOf'+a);
				descriptions[a][5] = []; // enabled / disabled
				var lic = null;
				var vmname = null;
				for(var b = 0; b < children.length; b++) {
					descriptions[a][5][b] = !$(children[b]).data('propdisabled');
					descriptions[a][3][$(children[b]).data('descOrder')] = $(children[b]).children('td:eq(1)').data('descValue');
					// check for license
					if(descriptions[a][0][b] == 'License') {
						lic = descriptions[a][2][b];
					} else if(descriptions[a][0][b] == 'Name') {
						vmname = descriptions[a][2][b]; 
					}
				}
				if(lic) {
					if(!vmname) vmname = trans('Virtual System %1','UIApplianceEditorWidget').replace('%1',a);
					licenses[licenses.length] = {'name':vmname,'license':lic};
				}
			}

			
			if(licenses.length) {
				
				var msg = trans('<b>The virtual system "%1" requires that you agree to the terms and conditions of the software license agreement shown below.</b><br /><br />Click <b>Agree</b> to continue or click <b>Disagree</b> to cancel the import.','UIImportLicenseViewer');
				var a = 0;
				var buttons = {};
				buttons[trans('Agree','UIImportLicenseViewer')] = function() {

					a++;
					if(a >= licenses.length) {
						$(this).remove();
						vboxImportApp();
						return;
					}
					$(this).dialog('close');
					$('#vboxImportWizLicTitle').html(msg.replace('%1',licenses[a]['name']));
					$('#vboxImportWizLicContent').val(licenses[a]['license']);
					$(this).dialog('open');

				};
				buttons[trans('Disagree','UIImportLicenseViewer')] = function() {
					$(this).remove();
				};
				
				var dlg = $('<div />').dialog({'closeOnEscape':false,'width':600,'height':500,'buttons':buttons,'modal':true,'autoOpen':false,'stack':true,'dialogClass':'vboxDialogContent vboxWizard','title':'<img src="images/vbox/os_type_16px.png" class="vboxDialogTitleIcon" /> ' + trans('Software License Agreement','UIImportLicenseViewer')});
				
				$(dlg).html('<p id="vboxImportWizLicTitle" /><textarea rows="20" spellcheck="false" wrap="off" readonly="true"id="vboxImportWizLicContent" style="width:100%; margin:2px; padding:2px;"></textarea>');
				$('#vboxImportWizLicTitle').html(msg.replace('%1',licenses[a]['name']));
				$('#vboxImportWizLicContent').val(licenses[a]['license']);
				$(dlg).dialog('open');

			} else {
				vboxImportApp();				
			}
			
	
		};
		vbw.run();
	};
	l.run();
}

/**
 * Run the export appliance wizard
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
					vmid = $(vmsAndProps[a]).data('vm').id;
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
		fe.add('fileExists',function(d){
			fileExists = d.exists;
		},{'file':loc});
		fe.onLoad = function() { 
			if(fileExists) {
				var buttons = {};
				buttons[trans('Yes','QIMessageBox')] = function() {
					vboxExportApp(1);
					$(this).remove();
				};
				vboxConfirm(trans('A file named <b>%1</b> already exists. Are you sure you want to replace it?<br /><br />Replacing it will overwrite its contents.','UIMessageCenter').replace('%1',vboxBasename(loc)),buttons,trans('No','QIMessageBox'));
				return;
			}
			vboxExportApp(0);
			
		};
		fe.run();



	};
	vbw.run();

}

/**
 * Show the port forwarding configuration dialog
 * @param {Array} rules - list of port forwarding rules to process
 * @param {Function} callback - function to run when "OK" is clicked
 */
function vboxPortForwardConfigInit(rules,callback) {
	var l = new vboxLoader();
	l.addFileToDOM("panes/settingsPortForwarding.html");
	l.onLoad = function(){
		vboxSettingsPortForwardingInit(rules);
		var buttons = {};
		buttons[trans('OK','QIMessageBox')] = function(){
			// Get rules
			var rules = $('#vboxSettingsPortForwardingList').children('tr');
			var rulesToPass = new Array();
			for(var i = 0; i < rules.length; i++) {
				if($(rules[i]).data('vboxRule')[3] == 0 || $(rules[i]).data('vboxRule')[5] == 0) {
					vboxAlert(trans('The current port forwarding rules are not valid. None of the host or guest port values may be set to zero.','UIMessageCenter'));
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
	};
	l.run();
}

/**
 * Run the New Virtual Machine Wizard
 * @param {Function} callback - function to run after VM creation
 */
function vboxWizardNewVMInit(callback) {

	var l = new vboxLoader();
	l.add('getMedia',function(d){$('#vboxIndex').data('vboxMedia',d);});
	
	l.onLoad = function() {

		var vbw = new vboxWizard('wizardNewVM',trans('Create New Virtual Machine','UINewVMWzd'),'images/vbox/vmw_new_welcome.png','images/vbox/vmw_new_welcome_bg.png','new');
		vbw.steps = 5;
		vbw.context = 'UINewHDWizard';
		vbw.perPageContext = 'UINewVMWzdPage%1';
		vbw.finishText = trans('Create','UINewVMWzd');
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
					lm.add('getMedia',function(d){$('#vboxIndex').data('vboxMedia',d);});
					lm.onLoad = function(){
						$('#vboxIndex').trigger('vmlistreload');
						if(callback) callback();
					};
					lm.run();
				}
			});			

		};
		vbw.run();
	};
	l.run();
	
}

/**
 * Run the Clone Virtual Machine Wizard
 * @param {Function} callback - callback to run after VM is cloned
 * @param {Object} args - wizard data - args.vm and args.snapshot should be populated
 * @see vboxWizard()
 */
function vboxWizardCloneVMInit(callback,args) {
	
	var l = new vboxLoader();
	l.add('getVMDetails',function(d){
		args.vm = d;
	},{'vm':args.vm.id});
	l.onLoad = function() {
		
		var vbw = new vboxWizard('wizardCloneVM',trans('Clone a virtual machine','UICloneVMWizard'),'images/vbox/vmw_clone.png','images/vbox/vmw_clone_bg.png','vm_clone');		
		vbw.steps = (args.vm.snapshotCount > 0 ? 3 : 2);
		vbw.args = args;
		vbw.finishText = trans('Clone','UICloneVMWizard');
		vbw.context = 'UICloneVMWizard';
		vbw.perPageContext = 'UICloneVMWizardPage%1';
		
		vbw.onFinish = function(wiz,dialog) {
	
			// Get parameters
			var name = document.forms['frmwizardCloneVM'].elements.cloneVMName.value;
			var src = vbw.args.vm.id;
			var snapshot = vbw.args.snapshot;
			var allNetcards = document.forms['frmwizardCloneVM'].elements.vboxCloneReinitNetwork.checked;
			
			// Only two steps? We can assume that state has no child states.
			// Force current state only
			var vmState = 'MachineState';
			if(wiz.steps > 2) {
				for(var a = 0; a < document.forms['frmwizardCloneVM'].elements.vmState.length; a++) {
					if(document.forms['frmwizardCloneVM'].elements.vmState[a].checked) {
						vmState = document.forms['frmwizardCloneVM'].elements.vmState[a].value;
						break;
					}
				}
			}
			
			// Full / linked clone
			var cLink = 0;
			if(document.forms['frmwizardCloneVM'].elements.vboxCloneType[1].checked) cLink = 1;			
			
			// wrap function
			var vbClone = function(sn) {
				
				var l = new vboxLoader();
				l.add('cloneVM',function(d,e){
					var registerVM = null;
					if(d && d.settingsFilePath) {
						registerVM = d.settingsFilePath;
					}
					if(d && d.progress) {
						vboxProgress(d.progress,function(ret) {
							vboxAjaxRequest('addVM',{'file':registerVM},function(){
								var ml = new vboxLoader();
								ml.add('getMedia',function(dat){$('#vboxIndex').data('vboxMedia',dat);});
								ml.onLoad = function() {
									$('#vboxIndex').trigger('vmlistreload');
									callback();
								};
								ml.run();
							});
						},d.id,'progress_clone_90px.png',trans('Clone a virtual machine','UICloneVMWizard'));
					} else {
						$('#vboxIndex').trigger('vmlistreload');
						callback();
					}
				},{'name':name,'vmState':vmState,'src':src,'snapshot':sn,'reinitNetwork':allNetcards,'link':cLink});
				l.run();				
			};
			
			// Check for linked clone, but not a snapshot
			if(cLink && !wiz.args.snapshot) {
	  	  		var sl = new vboxLoader();
	  	  		sl.add('snapshotTake',function(d){
					if(d && d.progress) {
						vboxProgress(d.progress,function(){
							var ml = new vboxLoader();
							ml.add('getVMDetails',function(md){
								vbClone(md.currentSnapshot);
							},{'vm':src});
							ml.run();
						},{},'progress_snapshot_create_90px.png',trans('Take Snapshot...','UIActionPool'));
					} else if(d && d.error) {
						vboxAlert(d.error);
					}
	 	  		},{'vm':src,'name':trans('Linked Base for %1 and %2','UICloneVMWizard').replace('%1',wiz.args.vm.name).replace('%2',name),'description':''});
				sl.run();
				
			// Just clone
			} else {
				vbClone(snapshot);
			}
			
			$(dialog).trigger('close').empty().remove();
	
		};
		vbw.run();
	};
	l.run();
}

/**
 * Run the VM Log Viewer dialog
 * @param {String} vm - uuid or name of virtual machine to obtain logs for
 */
function vboxShowLogsDialogInit(vm) {

	$('#vboxIndex').append($('<div />').attr({'id':'vboxVMLogsDialog'}));
	
	var l = new vboxLoader();
	l.add('getVMLogFilesInfo',function(r){
		$('#vboxVMLogsDialog').data({'logs':r.logs,'logpath':r.path});
	},{'vm':vm});
	l.addFileToDOM('panes/vmlogs.html',$('#vboxVMLogsDialog'));
	l.onLoad = function(){
		var buttons = {};
		buttons[trans('Refresh','VBoxVMLogViewer')] = function() {
			l = new vboxLoader();
			l.add('getVMLogFilesInfo',function(r){
				$('#vboxVMLogsDialog').data({'logs':r.logs,'logpath':r.path});
				
			},{'vm':vm});
			l.onLoad = function(){
				vboxShowLogsInit(vm);
			};
			l.run();
		};
		buttons[trans('Close','VBoxVMLogViewer')] = function(){$(this).trigger('close').empty().remove();};
		$('#vboxVMLogsDialog').dialog({'closeOnEscape':true,'width':800,'height':500,'buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent','title':'<img src="images/vbox/show_logs_16px.png" class="vboxDialogTitleIcon" /> '+ trans('%1 - VirtualBox Log Viewer','VBoxVMLogViewer').replace('%1',$('#vboxIndex').data('selectedVM').name)}).bind("dialogbeforeclose",function(){
	    	$(this).parent().find('span:contains("'+trans('Close','VBoxVMLogViewer')+'")').trigger('click');
	    });
		vboxShowLogsInit(vm);
	};
	l.run();

}

/**
 * Show the Virtual Media Manager Dialog
 * @param {Function} callback - optional function to run if media is selected and "OK" is clicked
 * @param {String} type - optionally restrict media to media of this type
 * @param {Boolean} hideDiff - optionally hide differencing HardDisk media
 * @param {String} mPath - optional path to use when adding or creating media through the VMM dialog
 */
function vboxVMMDialogInit(callback,type,hideDiff,mPath) {

	$('#vboxIndex').append($('<div />').attr({'id':'vboxVMMDialog','class':'vboxVMMDialog'}));
			
	var l = new vboxLoader();
	l.add('getConfig',function(d){$('#vboxIndex').data('vboxConfig',d);});
	l.add('getSystemProperties',function(d){$('#vboxIndex').data('vboxSystemProperties',d);});
	l.add('getMedia',function(d){$('#vboxIndex').data('vboxMedia',d);});
	l.addFileToDOM('panes/vmm.html',$('#vboxVMMDialog'));
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
					vboxMedia.updateRecent(vboxMedia.getMediumById($(sel).data('medium')));
					callback($(sel).data('medium'));
				}
				$('#vboxVMMDialog').trigger('close').empty().remove();
			};
		}
		buttons[trans('Close','VBoxMediaManagerDlg')] = function() {
			$('#vboxVMMDialog').trigger('close').empty().remove();
			if(callback) callback(null);
		};

		$("#vboxVMMDialog").dialog({'closeOnEscape':true,'width':800,'height':500,'buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent vboxVMMDialog','title':'<img src="images/vbox/diskimage_16px.png" class="vboxDialogTitleIcon" /> '+trans('Virtual Media Manager','VBoxMediaManagerDlg')}).bind("dialogbeforeclose",function(){
	    	$(this).parent().find('span:contains("'+trans('Close','VBoxMediaManagerDlg')+'")').trigger('click');
	    });
		
		vboxVMMInit(hideDiff,mPath);
		
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
	};
	l.run();
}

/**
 * Run the New Virtual Disk wizard
 * @param {Function} callback - callback function to run when new disk is created
 * @param {Object} suggested - sugggested defaults such as hard disk name and path
 */
function vboxWizardNewHDInit(callback,suggested) {

	var l = new vboxLoader();
	l.add('getSystemProperties',function(d){$('#vboxIndex').data('vboxSystemProperties',d);});
	l.add('getMedia',function(d){$('#vboxIndex').data('vboxMedia',d);});
	
	// Compose folder if suggested name exists
	if(suggested && suggested.name) {
		l.add('getComposedMachineFilename',function(d){suggested.path = vboxDirname(d.file)+$('#vboxIndex').data('vboxConfig').DSEP;},{'name':suggested.name});
	}
	l.onLoad = function() {
		
		var vbw = new vboxWizard('wizardNewHD',trans('Create New Virtual Disk','UINewHDWizard'),'images/vbox/vmw_new_harddisk.png','images/vbox/vmw_new_harddisk_bg.png','hd');
		vbw.steps = 4;
		vbw.suggested = suggested;
		vbw.context = 'UINewHDWizard';
		vbw.finishText = trans('Create','UINewHDWizard');
		vbw.height = 450;
		
		vbw.onFinish = function(wiz,dialog) {

			var file = $('#wizardNewHDLocationLabel').text();
			var size = vboxConvertMbytes(document.forms['frmwizardNewHD'].elements.wizardNewHDSizeValue.value);
			var type = (document.forms['frmwizardNewHD'].elements.newHardDiskType[1].checked ? 'fixed' : 'dynamic');
			var format = document.forms['frmwizardNewHD'].elements['newHardDiskFileType'];
			for(var i = 0; i < format.length; i++) {
				if(format[i].checked) {
					format=format[i].value;
					break;
				}
			}
			var fsplit = (document.forms['frmwizardNewHD'].newHardDiskSplit.checked ? 1 : 0);

			$(dialog).trigger('close').empty().remove();

			var l = new vboxLoader();
			l.add('mediumCreateBaseStorage',function(d,e){
				if(d && d.progress) {
					vboxProgress(d.progress,function(ret,mid) {
							var ml = new vboxLoader();
							ml.add('getMedia',function(dat){$('#vboxIndex').data('vboxMedia',dat);});
							ml.onLoad = function() {
								med = vboxMedia.getMediumById(mid);
								vboxMedia.updateRecent(med);
								callback(mid);
							};
							ml.run();
					},d.id,'progress_media_create_90px.png',trans('Create New Virtual Disk','UINewHDWizard'));
				} else {
					callback({},d.id);
				}
			},{'file':file,'type':type,'size':size,'format':format,'split':fsplit});
			l.run();
			
		};
		vbw.run();
		
		
	};
	l.run();
	
}

/**
 * Run the Copy Virtual Disk wizard
 * @param {Function} callback - callback function to run when new disk is created
 * @param {Object} suggested - sugggested defaults such as hard disk name and path
 */
function vboxWizardCopyHDInit(callback,suggested) {

	var l = new vboxLoader();
	l.add('getSystemProperties',function(d){$('#vboxIndex').data('vboxSystemProperties',d);});
	l.add('getMedia',function(d){$('#vboxIndex').data('vboxMedia',d);});
	
	l.onLoad = function() {
		
		var vbw = new vboxWizard('wizardCopyHD',trans('Copy Virtual Disk','UINewHDWizard'),'images/vbox/vmw_new_harddisk.png','images/vbox/vmw_new_harddisk_bg.png','hd');
		vbw.steps = 5;
		vbw.suggested = suggested;
		vbw.context = 'UINewHDWizard';
		vbw.finishText = trans('Copy','UINewHDWizard');
		vbw.height = 450;
		
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
			var fsplit = (document.forms['frmwizardCopyHD'].newHardDiskSplit.checked && format == 'vmdk' ? 1 : 0);
			var location = $('#wizardCopyHDLocationLabel').text();
						
			$(dialog).trigger('close').empty().remove();

			var l = new vboxLoader();
			l.add('mediumCloneTo',function(d,e){
				if(d && d.progress) {
					vboxProgress(d.progress,function(ret,mid) {
							var ml = new vboxLoader();
							ml.add('getMedia',function(dat){$('#vboxIndex').data('vboxMedia',dat);});
							ml.onLoad = function() {
								med = vboxMedia.getMediumById(mid);
								vboxMedia.updateRecent(med);
								callback(mid);
							};
							ml.run();
					},d.id,'progress_media_create_90px.png',trans('Copy Virtual Disk','UINewHDWizard'));
				} else {
					callback(d.id);
				}
			},{'src':src,'type':type,'format':format,'location':location,'split':fsplit});
			l.run();
			
		};
		vbw.run();
	};
	l.run();
	
}

/**
 * Display guest network adapters dialog
 * @param {String} vm - virtual machine uuid or name
 */
function vboxGuestNetworkAdaptersDialogInit(vm) {

	/*
	 * 	Dialog
	 */
	$('#vboxIndex').append($('<div />').attr({'id':'vboxGuestNetworkDialog','style':'display: none'}));

	/*
	 * Loader
	 */
	var l = new vboxLoader();
	l.addFileToDOM('panes/guestNetAdapters.html',$('#vboxGuestNetworkDialog'));
	l.onLoad = function(){
		
		var buttons = {};
		buttons[trans('Close','VBoxVMLogViewer')] = function() {$('#vboxGuestNetworkDialog').trigger('close').empty().remove();};
		$('#vboxGuestNetworkDialog').dialog({'closeOnEscape':true,'width':500,'height':250,'buttons':buttons,'modal':true,'autoOpen':true,'stack':true,'dialogClass':'vboxDialogContent','title':'<img src="images/vbox/nw_16px.png" class="vboxDialogTitleIcon" /> ' + trans('Guest Network Adapters','VBoxGlobal')}).bind("dialogbeforeclose",function(){
	    	$(this).parent().find('span:contains("'+trans('Close','VBoxVMLogViewer')+'")').trigger('click');
	    });
		
		// defined in pane
		vboxVMNetAdaptersInit(vm,nic);
	};
	l.run();
	
}


/**
 * Display Global Preferences dialog
 */

function vboxPrefsInit() {
	
	// Prefs
	var panes = new Array(
		{'name':'GlobalGeneral','label':'General','icon':'machine','context':'UIGlobalSettingsGeneral'},
		{'name':'GlobalLanguage','label':'Language','icon':'site','context':'UIGlobalSettingsLanguage'},
		{'name':'GlobalNetwork','label':'Network','icon':'nw','context':'UIGlobalSettingsNetwork'},
		{'name':'GlobalUsers','label':'Users','icon':'register','context':'UIUsers'}
	);
	
	var data = new Array(
		{'fn':'getHostOnlyNetworking','callback':function(d){$('#vboxSettingsDialog').data('vboxHostOnlyNetworking',d);}},
		{'fn':'getSystemProperties','callback':function(d){$('#vboxSettingsDialog').data('vboxSystemProperties',d);}},
		{'fn':'getUsers','callback':function(d){$('#vboxSettingsDialog').data('vboxUsers',d);}}
	);	
	
	// Check for noAuth setting
	if($('#vboxIndex').data('vboxConfig').noAuth || !$('#vboxIndex').data('vboxSession').admin || !$('#vboxIndex').data('vboxConfig').authCapabilities.canModifyUsers) {
		panes.pop();
		data.pop();
	}
	
	vboxSettingsDialog(trans('Preferences...','VBoxSelectorWnd').replace(/\./g,''),panes,data,function(){
		
		var l = new vboxLoader();

		// Language change?
		if($('#vboxSettingsDialog').data('language') && $('#vboxSettingsDialog').data('language') != __vboxLangName) {
			vboxSetCookie('vboxLanguage',$('#vboxSettingsDialog').data('language'));
			l.onLoad = function(){location.reload(true);};
			
		// Update host info in case interfaces were added / removed
		} else if($('#vboxIndex').data('selectedVM') && $('#vboxIndex').data('selectedVM')['id'] == 'host') {
			l.onLoad = function() {
				$('#vboxIndex').trigger('vmselect',[$('#vboxIndex').data('selectedVM')]);
			};
		}
		l.add('saveHostOnlyInterfaces',function(){},{'networkInterfaces':$('#vboxSettingsDialog').data('vboxHostOnlyNetworking').networkInterfaces});
		l.add('saveSystemProperties',function(){},{'SystemProperties':$('#vboxSettingsDialog').data('vboxSystemProperties')});
		l.run();
		
		// Update default machine folder
		$('#vboxIndex').data('vboxSystemProperties').defaultMachineFolder = $('#vboxSettingsDialog').data('vboxSystemProperties').defaultMachineFolder;
		
	},null,'global_settings','UISettingsDialogGlobal');
}



/**
 * Display a virtual machine settings dialog
 * @param {String} vm - uuid or name of virtual machine
 * @param {Function} callback - callback function to perform after settings have been saved
 * @param {String} pane - optionally automatically select pane when dialog is displayed
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
		{'fn':'getMedia','callback':function(d){$('#vboxIndex').data('vboxMedia',d);}},
		{'fn':'getHostNetworking','callback':function(d){$('#vboxSettingsDialog').data('vboxHostNetworking',d);}},
		{'fn':'getHostDetails','callback':function(d){$('#vboxSettingsDialog').data('vboxHostDetails',d);}},
		{'fn':'getVMDetails','callback':function(d){$('#vboxSettingsDialog').data('vboxMachineData',d);},'args':{'vm':vm,'force_refresh':($('#vboxIndex').data('vboxConfig').vmConfigRefresh || $('#vboxIndex').data('vboxConfig').enableHDFlushConfig)}},
		{'fn':'getEnumNetworkAdapterType','callback':function(d){$('#vboxSettingsDialog').data('vboxNetworkAdapterTypes',d);}},
		{'fn':'getEnumAudioControllerType','callback':function(d){$('#vboxSettingsDialog').data('vboxAudioControllerTypes',d);}},
		{'fn':'getRecentMedia','callback':function(d){$('#vboxIndex').data('vboxRecentMedia',d);}},
		{'fn':'getVMTransientSharedFolders','callback':function(d){$('#vboxSettingsDialog').data('vboxTransientSharedFolders',d);},'args':{'vm':vm}}

	);

	vboxSettingsDialog($('#vboxIndex').data('selectedVM').name + ' - ' + trans('Settings','UISettingsDialog'),panes,data,function(){
		var loader = new vboxLoader();
		var sdata = $.extend($('#vboxSettingsDialog').data('vboxMachineData'),{'clientConfig':$('#vboxIndex').data('vboxConfig')});
		loader.add('saveVM',function(){return;},sdata);
		loader.onLoad = function() {
			// Refresh media
			var mload = new vboxLoader();
			mload.add('getMedia',function(d){$('#vboxIndex').data('vboxMedia',d);});
			mload.onLoad = function() {
				if(callback){callback();}
			};
			mload.run();
		};
		loader.run();
	},pane,'settings','UISettingsDialogMachine');
}

/**
 * Display Network settings dialog for VM when VM is running
 * @param {String} vm - uuid or name of virtual machine
 * @param {Function} callback - callback function to perform after settings have been saved
 */
function vboxVMsettingsInitNetwork(vm,callback) {
	
	var panes = new Array(
		{'name':'Network','label':'Network','icon':'nw','tabbed':true,'context':'UIMachineSettingsNetwork'}
	);
	
	var data = new Array(
			{'fn':'getHostNetworking','callback':function(d){$('#vboxSettingsDialog').data('vboxHostNetworking',d);}},
			{'fn':'getHostDetails','callback':function(d){$('#vboxSettingsDialog').data('vboxHostDetails',d);}},
			{'fn':'getVMDetails','callback':function(d){$('#vboxSettingsDialog').data('vboxMachineData',d);},'args':{'vm':vm}},
			{'fn':'getEnumNetworkAdapterType','callback':function(d){$('#vboxSettingsDialog').data('vboxNetworkAdapterTypes',d);}},
			{'fn':'getEnumAudioControllerType','callback':function(d){$('#vboxSettingsDialog').data('vboxAudioControllerTypes',d);}}

	);

	vboxSettingsDialog(trans('Settings','VBoxSettingsDialog'),panes,data,function(){
		var loader = new vboxLoader();
		var sdata = $.extend($('#vboxSettingsDialog').data('vboxMachineData'),{'clientConfig':$('#vboxIndex').data('vboxConfig')});
		loader.add('saveVMNetwork',function(){if(callback){callback();}},sdata);
		loader.run();
	},'Network','nw','UISettingsDialogMachine');
}

/**
 * Display SharedFolders settings dialog for VM when VM is running 
 * @param {String} vm - uuid or name of virtual machine
 * @param {Function} callback - callback function to perform after settings have been saved
 */
function vboxVMsettingsInitSharedFolders(vm,callback) {
	
	var panes = new Array(
		{'name':'SharedFolders','label':'Shared Folders','icon':'shared_folder','tabbed':false,'context':'UIMachineSettingsSF'}
	);
	
	var data = new Array(
			{'fn':'getHostDetails','callback':function(d){$('#vboxSettingsDialog').data('vboxHostDetails',d);}},
			{'fn':'getVMDetails','callback':function(d){$('#vboxSettingsDialog').data('vboxMachineData',d);},'args':{'vm':vm}},
			{'fn':'getVMTransientSharedFolders','callback':function(d){$('#vboxSettingsDialog').data('vboxTransientSharedFolders',d);},'args':{'vm':vm}}
	);

	vboxSettingsDialog(trans('Settings','VBoxSettingsDialog'),panes,data,function(){
		var sdata = $.extend($('#vboxSettingsDialog').data('vboxMachineData'),{'clientConfig':$('#vboxIndex').data('vboxConfig')});
		var loader = new vboxLoader();
		loader.add('saveVMSharedFolders',function(){if(callback){callback();}},sdata);
		loader.run();
	},'SharedFolders','shared_folder','UISettingsDialogMachine');
}



/**
 * Display a settings dialog (generic) called by dialog initializers
 * @param {String} title - title of dialog
 * @param {Array} panes - list of panes {Object} to load
 * @param {Object} data - list of data to load
 * @param {Function} onsave - callback function to run when "OK" is clicked
 * @param {String} pane - optionally automatically select pane when dialog is shown
 * @param {String} icon - optional URL to icon for dialog
 * @param {String} langContext - language context to use for translations
 * @see trans()
 */
function vboxSettingsDialog(title,panes,data,onsave,pane,icon,langContext) {
	
	var d = $('<div />').attr({'id':'vboxSettingsDialog','style':'display: none;'});
	
	var f = $('<form />').attr({'name':'frmVboxSettings','style':'height: 100%'});
	
	var t = $('<table />').attr({'style':'height: 100%;','class':'vboxSettingsTable'});
	
	var tr = $('<tr />');
	
	$($('<td />').attr({'id':'vboxSettingsMenu','style': (panes.length == 1 ? 'display:none;' : '')})).append($('<ul />').attr({'id':'vboxSettingsMenuList','class':'vboxHover'})).appendTo(tr);
	
	var td = $('<td />').attr({'id':'vboxSettingsPane'}).css({'height':'100%'});
	
	// Settings table contains title and visible settings pane
	var stbl = $('<table />').css({'height':'100%','width':'100%','padding':'0px','margin':'0px','border':'0px','border-spacing':'0px'});
	
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
		
		loader.addFileToDOM('panes/settings'+panes[i].name+'.html',$('#vboxSettingsPane-'+panes[i].name));
		
	}

	loader.onLoad = function(){
		
		/* Init UI Items */
		for(var i = 0; i < panes.length; i++) {
			vboxInitDisplay($('#vboxSettingsPane-'+panes[i].name),panes[i].context);
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
	    	var h = $('#vboxSettingsList').css({'display':'none'}).parent().innerHeight();
	    	$('#vboxSettingsList').height(h-8).css({'display':''});	    	
	    });
	    
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
	    	$('#vboxSettingsDialog').dialog('option','title',(icon ? '<img src="images/vbox/'+icon+'_16px.png" class="vboxDialogTitleIcon" /> ' : '') + trans(panes[0].label,langContext));
	    }
	    
		
	};
	
	loader.run();

}


