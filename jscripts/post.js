var Post = {
	init: function()
	{
	},

	removeAttachment: function(aid)
	{
		if(confirm(removeattach_confirm) == true)
		{
			document.input.removeattachment.value = aid;
		}
		else
		{
			document.input.removeattachment.value = 0;
			return false;
		}
	}
}
Event.observe(window, 'load', Post.init);